# Altima MLM Smart Contract & Compensation Engine
## Technical Specification & Architecture Manual

---

### Executive Overview

**Altima** is a decentralized, peer-to-peer (P2P) direct-selling referral ecosystem built on the Binance Smart Chain (BSC - BEP-20 standard). It utilizes a dynamic administrative architecture powering a forced 3x3 Breadth-First Search (BFS) spillover matrix and a multi-tiered cyclical progression pool sequence starting with the entry **5 USDT Pool** followed by **10, 30, 50, 100, 200, 500, 1000, and 2000 USDT pools**.

This document outlines the end-to-end technical specification, smart contract state structures, placement algorithms, financial ledger equations, and state machine transition rules for Altima.

---

## 1. System Architecture, Admin Governance & State Variables

### 1.1 High-Level Architecture

The system is modularly organized into five core smart contract modules:
1. `AltimaGovernance.sol`: Handles administrative governance, access control, and dynamic parameter configuration.
2. `AltimaCore.sol`: Coordinates registration, direct referrals, and user identity mappings.
3. `MatrixEngine.sol`: Executes the forced 3x3 BFS spillover placement algorithm across 7 levels.
4. `PoolEngine.sol`: Manages cyclical progression pools (5, 10, 30, 50, 100, 200, 500, 1000, 2000 USDT).
5. `VaultEngine.sol`: Handles P2P fund routing, instant direct wallet transfers, and rebirth funding pools with 0% corporate retention.

```
+-----------------------------------------------------------------------+
|                           AltimaGovernance                            |
|    (Dynamic Parameter Config, Admin Limits, Direct & Matrix Slabs)    |
+-----------------------------------+-----------------------------------+
                                    |
                                    v
+-----------------------------------+-----------------------------------+
|                              AltimaCore                               |
|        (User Registration, Upline Mapping, Referral Tree State)       |
+-----------------+---------------------------------+-------------------+
                  |                                 |
                  v                                 v
+-----------------+-----------------+     +---------+-------------------+
|          MatrixEngine             |     |           PoolEngine            |
|  (3x3 BFS Spillover across 7 Lvs) |     | (Progression Pools: 5..2000 USDT) |
+-----------------+-----------------+     +---------+-------------------+
                  |                                 |
                  +-----------------+---------------+
                                    |
                                    v
+-----------------------------------+-----------------------------------+
|                             VaultEngine                               |
|     (P2P Liquidity Routing, Instant Balances, Rebirth Allocations)    |
+-----------------------------------------------------------------------+
```

---

### 1.2 Admin Governance & Configurable Parameters

The administrator possesses dynamic control to calibrate reward structures during initial deployment and ongoing operational cycles without modifying core business logic.

#### Admin-Configurable Variables:
- **`directBonusSchedule`**: Direct referral payouts per direct signup or milestone achievement (e.g., 3 direct signups = 30 USDT fee recovery).
- **`matrixLevelRewards[7]`**: Depth payout amounts/percentages for matrix Levels 1 through 7 in the 30 USDT foundational matrix.
- **`poolConfig[9]`**: Parameters for Progression Pools (5, 10, 30, 50, 100, 200, 500, 1000, 2000 USDT), including depth payouts, node capacities, and graduation thresholds.
- **`rebirthThresholds`**: Milestone payout/level triggers that automatically allocate liquidity into parallel rebirth nodes.

---

### 1.3 Smart Contract Data Structures & State Variables (Solidity)

```solidity
// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

import "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import "@openzeppelin/contracts/access/Ownable.sol";
import "@openzeppelin/contracts/utils/ReentrancyGuard.sol";

contract AltimaStorage is Ownable, ReentrancyGuard {

    enum PoolTier { NONE, POOL_5, POOL_10, POOL_30, POOL_50, POOL_100, POOL_200, POOL_500, POOL_1000, POOL_2000 }

    struct User {
        uint256 id;
        address wallet;
        address referrer;
        uint256 directReferralCount;
        uint256 totalEarnings;
        uint256 activeFoundationalPositions;
        bool isGraduated;
        PoolTier currentPoolTier;
    }

    struct MatrixNode {
        uint256 nodeId;
        address owner;
        uint256 parentNodeId;
        uint256[] childNodeIds;
        uint8 level; // 1 to 7
        uint32 depthPosition;
    }

    struct LevelConfig {
        uint256 payoutAmount;    // Configurable USDT per node fill
        uint256 rebirthDeduction; // Amount diverted to rebirth fund
        bool triggersRebirth;    // Flag indicating rebirth activation
    }

    struct PoolConfig {
        uint256 entryFee;         // 5, 10, 30, 50, 100, 200, 500, 1000, or 2000 USDT
        uint256 maxDepth;         // Pool matrix depth (3x2)
        uint256 d1Payout;         // Depth 1 payout
        uint256 d2Payout;         // Depth 2 payout
    }

    // State Mappings
    IERC20 public usdtToken;

    uint256 public totalUsers;
    uint256 public totalMatrixNodes;

    mapping(address => User) public users;
    mapping(uint256 => address) public userAddressById;
    mapping(uint256 => MatrixNode) public matrixNodes;
    mapping(address => uint256[]) public userNodeIds;

    // Dynamic Governance Configurations
    uint256 public entryFeeFoundational = 30 * 1e18; // 30 USDT
    uint256 public directReferralBonus = 10 * 1e18;  // Default 10 USDT per direct (3 = 30 USDT)
    uint256 public directReferralMilestoneCount = 3;  // 3 Directs rule
    uint256 public directReferralMilestoneReward = 30 * 1e18; // 30 USDT recovery

    LevelConfig[7] public matrixLevelConfigs;
    mapping(PoolTier => PoolConfig) public poolConfigs;

    // Ledger & Withdrawable Balances (P2P zero company retainment)
    mapping(address => uint256) public withdrawableBalance;
    mapping(address => uint256) public rebirthVaultBalance;

    // Events
    event UserRegistered(address indexed user, address indexed referrer, uint256 userId);
    event DirectBonusPaid(address indexed referrer, address indexed referee, uint256 amount);
    event MatrixNodePlaced(uint256 indexed nodeId, address indexed owner, uint256 indexed parentNodeId, uint8 level);
    event LevelPayoutDistributed(address indexed recipient, uint256 indexed nodeId, uint8 level, uint256 amount);
    event RebirthTriggered(address indexed owner, uint256 newPositionId, uint8 triggeredAtLevel);
    event PoolGraduated(address indexed user, PoolTier completedTier, PoolTier nextTier);
    event AdminConfigUpdated(string parameterName, uint256 newValue);

    constructor(address _usdt) Ownable(msg.sender) {
        usdtToken = IERC20(_usdt);
    }
}
```

---

## 2. Matrix Placement & Spillover Algorithm

### 2.1 Forced 3x3 BFS Spillover Logic

The foundational matrix is a **Forced 3x3 Matrix** structured across **7 Levels**. Each node can accommodate exactly 3 direct child nodes ($k = 3$).

- **Level Capacities:**
  - Level 1: $3^1 = 3$ nodes
  - Level 2: $3^2 = 9$ nodes
  - Level 3: $3^3 = 27$ nodes
  - Level 4: $3^4 = 81$ nodes
  - Level 5: $3^5 = 243$ nodes
  - Level 6: $3^6 = 729$ nodes
  - Level 7: $3^7 = 2,187$ nodes
  - **Total Tree Capacity:** $3 + 9 + 27 + 81 + 243 + 729 + 2,187 = 3,279$ nodes.

---

## 3. Financial Flow & P2P Ledger

### 3.1 100% P2P Allocation Model (Zero Company Deduction)

Altima operates on a **100% Peer-to-Peer decentralized distribution model**. The protocol retains **0% corporate fee**. Every $30 USDT activation fee is immediately routed to active member wallets and rebirth reserves.

---

## 4. Progression Pool Transition Logic

### 4.1 Pool Hierarchy & Sequential Progression

Upon completing Level 7 of the foundational matrix, members graduate into cyclical higher-tier Progression Pools starting with the entry **5 USDT Pool**:

$$\text{Foundational Matrix (30 USDT)} \xrightarrow{\text{Graduation}} 5 \rightarrow 10 \rightarrow 30 \rightarrow 50 \rightarrow 100 \rightarrow 200 \rightarrow 500 \rightarrow 1000 \rightarrow 2000 \text{ USDT Pools}$$

```
+-----------------------------------------------------------------+
|                 Foundational Matrix (30 USDT)                   |
+--------------------------------+--------------------------------+
                                 |
                                 v
+--------------------------------+--------------------------------+
|                   Entry Pool: 5 USDT Pool                       |
|                   (3x2 Matrix - 12 Nodes)                       |
+--------------------------------+--------------------------------+
                                 |
                                 v
+--------------------------------+--------------------------------+
|          10 -> 30 -> 50 -> 100 -> 200 USDT Pools                |
+--------------------------------+--------------------------------+
                                 |
                                 v
+--------------------------------+--------------------------------+
|                    500 USDT Pool (3x2)                           |
+--------------------------------+--------------------------------+
                                 |
                                 v
+--------------------------------+--------------------------------+
|                   1000 USDT Pool (3x2)                          |
+--------------------------------+--------------------------------+
                                 |
                                 v
+--------------------------------+--------------------------------+
|                   2000 USDT Pool (3x2)                          |
+-----------------------------------------------------------------+
```

---

### 4.2 Pool Structure & Payout Breakdown

Each progression pool operates as an accelerated **3x2 Matrix** (3 child nodes at depth 1, 9 child nodes at depth 2 = 12 total nodes):

1. **5 USDT Entry Pool:** Depth 1 Payout: $1.00 USDT | Depth 2 Payout: $3.00 USDT
2. **10 USDT Progression Pool:** Depth 1 Payout: $2.00 USDT | Depth 2 Payout: $6.00 USDT
3. **30 USDT Progression Pool:** Depth 1 Payout: $6.00 USDT | Depth 2 Payout: $18.00 USDT
4. **50 USDT Progression Pool:** Depth 1 Payout: $10.00 USDT | Depth 2 Payout: $30.00 USDT
5. **100 USDT Progression Pool:** Depth 1 Payout: $20.00 USDT | Depth 2 Payout: $60.00 USDT
6. **200 USDT Progression Pool:** Depth 1 Payout: $40.00 USDT | Depth 2 Payout: $120.00 USDT
7. **500 USDT Progression Pool:** Depth 1 Payout: $100.00 USDT | Depth 2 Payout: $300.00 USDT
8. **1000 USDT Progression Pool:** Depth 1 Payout: $200.00 USDT | Depth 2 Payout: $600.00 USDT
9. **2000 USDT Progression Pool:** Depth 1 Payout: $400.00 USDT | Depth 2 Payout: $1,200.00 USDT

---

### 4.3 State Machine Transition Specification

```
   [UNINITIALIZED]
          |
          | registerUser(referrer) [30 USDT]
          v
   [ACTIVE_FOUNDATIONAL]
          |
          | fillLevel7() / graduate()
          v
   [ACTIVE_POOL_5] -> [ACTIVE_POOL_10] -> [ACTIVE_POOL_30] -> [ACTIVE_POOL_50] -> [ACTIVE_POOL_100] -> [ACTIVE_POOL_200] -> [ACTIVE_POOL_500] -> [ACTIVE_POOL_1000] -> [ACTIVE_POOL_2000]
```
