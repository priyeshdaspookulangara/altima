# Altima MLM Smart Contract & Compensation Engine
## Technical Specification & Architecture Manual

---

### Executive Overview

**Altima** is a decentralized, peer-to-peer (P2P) direct-selling referral ecosystem built on the Binance Smart Chain (BSC - BEP-20 standard). It utilizes a dynamic administrative architecture powering a forced 3x3 Breadth-First Search (BFS) spillover matrix and a multi-tiered cyclical progression pool system (50 USDT, 100 USDT, and 200 USDT pools).

This document outlines the end-to-end technical specification, smart contract state structures, placement algorithms, financial ledger equations, and state machine transition rules for Altima.

---

## 1. System Architecture, Admin Governance & State Variables

### 1.1 High-Level Architecture

The system is modularly organized into five core smart contract modules:
1. `AltimaGovernance.sol`: Handles administrative governance, access control, and dynamic parameter configuration.
2. `AltimaCore.sol`: Coordinates registration, direct referrals, and user identity mappings.
3. `MatrixEngine.sol`: Executes the forced 3x3 BFS spillover placement algorithm across 7 levels.
4. `PoolEngine.sol`: Manages cyclical progression pools (50 USDT, 100 USDT, 200 USDT).
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
|  (3x3 BFS Spillover across 7 Lvs) |     | (Progression Pools: 50/100/200)   |
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
- **`poolConfig[3]`**: Parameters for Progression Pools (50 USDT, 100 USDT, 200 USDT), including depth payouts, node capacities, and graduation thresholds.
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

    enum PoolTier { NONE, POOL_50, POOL_100, POOL_200 }

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
        uint256 entryFee;         // 50, 100, or 200 USDT
        uint256 maxDepth;         // Pool matrix depth (e.g., 2 or 3)
        uint256 nodePayout;       // Reward per node fill in pool
        uint256 graduationBonus; // Bonus upon cycle completion
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

When a user joins or triggers a rebirth, the placement engine conducts a **Breadth-First Search (BFS)** starting from the referrer's primary node down to Level 7. The system scans level-by-level, left-to-right, to find the first node with fewer than 3 children (`childNodeIds.length < 3`).

```
                   [ Referrer Node ] (Level 0)
                          |
        +-----------------+-----------------+
        |                 |                 |
    [ Child 1 ]       [ Child 2 ]       [ Child 3 ] (Level 1)
        |                 |                 |
     +--+--+           +--+--+           +--+--+
     |  |  |           |  |  |           |  |  |
    (1)(2)(3)         (4)(5)(6)         (7)(8)(9)    (Level 2 - BFS Left-to-Right)
```

---

### 2.2 Algorithm Pseudocode

```python
def find_first_available_node(root_node_id):
    """
    Executes Level-Order Breadth-First Search (BFS) to find the leftmost
    node with an available child slot (< 3 children) within 7 levels depth.
    """
    queue = [root_node_id]

    while len(queue) > 0:
        current_node_id = queue.pop(0)
        current_node = matrixNodes[current_node_id]

        # Check if node has available slot
        if len(current_node.childNodeIds) < 3:
            return current_node_id

        # Add children to queue for level-by-level evaluation
        for child_id in current_node.childNodeIds:
            # Depth restriction check: level cannot exceed 7
            if matrixNodes[child_id].level < 7:
                queue.append(child_id)

    raise Exception("Matrix branch fully saturated (3,279 positions filled)")

def place_matrix_node(user_address, referrer_address):
    referrer_root = get_primary_node(referrer_address)
    target_parent_id = find_first_available_node(referrer_root)

    # Instantiate new node
    new_node_id = generate_next_node_id()
    parent_node = matrixNodes[target_parent_id]
    new_level = parent_node.level + 1

    new_node = MatrixNode(
        nodeId=new_node_id,
        owner=user_address,
        parentNodeId=target_parent_id,
        childNodeIds=[],
        level=new_level
    )

    matrixNodes[new_node_id] = new_node
    parent_node.childNodeIds.append(new_node_id)

    # Trigger P2P upline distribution across 7 levels
    distribute_matrix_depth_payouts(new_node_id)
    return new_node_id
```

---

### 2.3 On-Chain Gas Optimization Strategy

To maintain EVM efficiency and prevent unbounded loops during queue traversal:
1. **BFS Queue Window Limit:** On-chain BFS search is bounded to a maximum breadth pointer array per level.
2. **Pointer Mapping:** Maintaining `mapping(uint256 => uint256) public nextAvailableChildPointer` allows $O(1)$ lookup for node auto-spillover in non-saturated branches.
3. **Off-Chain Indexer Verification:** Large tree traversals can be computed off-chain by relayers or web3 clients with zero-knowledge proof or signature validation on-chain to minimize gas consumption.

---

## 3. Financial Flow & P2P Ledger

### 3.1 100% P2P Allocation Model (Zero Company Deduction)

Altima operates on a **100% Peer-to-Peer decentralized distribution model**. The protocol retains **0% corporate fee**. Every $30 USDT activation fee is immediately routed to active member wallets and rebirth reserves.

```
       [ 30 USDT Entry Fee ]
                 |
   +-------------+-------------+
   |                           |
   v                           v
 Direct Referral /         Matrix Depth
 Milestone Pool            Payout Stream
 (e.g., 10 USDT/30 USDT)   (7 Levels Upline)
```

---

### 3.2 30 USDT Entry Breakdown & 3-Direct 100% Fee Recovery Rule

- **Activation Amount:** 30 USDT.
- **Direct Referral Schedule:**
  - Default rate: 10 USDT per direct referral.
  - **Milestone Guarantee:** Upon achieving **3 Direct Referrals**, the contract calculates $3 \times 10\text{ USDT} = 30\text{ USDT}$, resulting in **100% joining fee recovery**.
  - Subsequent direct referrals continue to yield direct referral rewards to the referrer according to dynamic admin parameters.

---

### 3.3 Foundational Matrix Depth Distribution Table (Levels 1–7)

The remaining funds from the 30 USDT activation fee are distributed upline through 7 levels:

| Level | Matrix Capacity | Payout per Fill (USDT) | Level Subtotal (USDT) | Rebirth Deduction (USDT) | Net Member Balance (USDT) |
|---|---|---|---|---|---|
| **Level 1** | 3 | $2.00 | $6.00 | $0.00 | $6.00 |
| **Level 2** | 9 | $2.00 | $18.00 | $0.00 | $18.00 |
| **Level 3** | 27 | $2.00 | $54.00 | $10.00 | $44.00 (Triggers Rebirth #1) |
| **Level 4** | 81 | $2.00 | $162.00 | $20.00 | $142.00 (Triggers Rebirth #2) |
| **Level 5** | 243 | $3.00 | $729.00 | $30.00 | $699.00 (Triggers Rebirth #3) |
| **Level 6** | 729 | $3.00 | $2,187.00 | $50.00 | $2,137.00 (Triggers Rebirth #4) |
| **Level 7** | 2,187 | $4.00 | $8,748.00 | $100.00 | $8,648.00 (Graduation Event) |
| **TOTAL** | **3,279** | -- | **$11,904.00** | **$210.00** | **$11,694.00** |

*Note: Payout amounts and rebirth deductions are dynamically configurable by the administrator.*

---

### 3.4 Rebirth & Flywheel Mechanics

1. **Self-Funding Mechanism:** Starting at designated milestone fills (e.g., Level 3 completion), the smart contract deducts a predefined rebirth allocation into `rebirthVaultBalance[user]`.
2. **Flywheel Position Spawn:** Once the rebirth vault reaches $30\text{ USDT}$, the contract automatically executes `place_matrix_node(user, user.referrer)`, inserting a new parallel node at the bottom of the global matrix tree via BFS spillover.
3. **Continuous Downline Support:** Rebirth nodes act as fresh active participants in lower tiers, generating payouts for upline members in those tiers and driving continuous momentum.

---

## 4. Progression Pool Transition Logic

### 4.1 Pool Hierarchy & Sequential Progression

Upon completing Level 7 of the foundational matrix, members graduate into cyclical higher-tier Progression Pools:

$$\text{Foundational 30 USDT Matrix} \xrightarrow{\text{Graduation}} \text{50 USDT Pool} \xrightarrow{\text{Completion}} \text{100 USDT Pool} \xrightarrow{\text{Completion}} \text{200 USDT Pool}$$

```
+-----------------------------------------------------------------+
|                 Foundational Matrix (30 USDT)                   |
|              (Completes Level 7 - 2,187 nodes)                  |
+--------------------------------+--------------------------------+
                                 |
                                 v
+--------------------------------+--------------------------------+
|                     Progression Tier 1: 50 USDT Pool             |
|                   (3x2 Matrix - 12 Nodes Capacity)              |
+--------------------------------+--------------------------------+
                                 |
                                 v
+--------------------------------+--------------------------------+
|                    Progression Tier 2: 100 USDT Pool            |
|                   (3x2 Matrix - 12 Nodes Capacity)              |
+--------------------------------+--------------------------------+
                                 |
                                 v
+--------------------------------+--------------------------------+
|                    Progression Tier 3: 200 USDT Pool            |
|                   (3x2 Matrix - 12 Nodes Capacity)              |
+-----------------------------------------------------------------+
```

---

### 4.2 Pool Structure & Payout Breakdown

Each progression pool operates as an accelerated **3x2 Matrix** (3 child nodes at depth 1, 9 child nodes at depth 2 = 12 total nodes):

#### 1. 50 USDT Progression Pool
- **Entry Fee:** 50 USDT (Auto-rolled from Level 7 payouts or wallet balance).
- **Depth 1 Payout (3 nodes):** 10 USDT per fill = 30 USDT.
- **Depth 2 Payout (9 nodes):** 30 USDT per fill = 270 USDT.
- **Cycle Total Yield:** 300 USDT.
- **Transition:** 100 USDT auto-allocated to Tier 2 Pool; 200 USDT withdrawable net profit.

#### 2. 100 USDT Progression Pool
- **Entry Fee:** 100 USDT.
- **Depth 1 Payout (3 nodes):** 20 USDT per fill = 60 USDT.
- **Depth 2 Payout (9 nodes):** 60 USDT per fill = 540 USDT.
- **Cycle Total Yield:** 600 USDT.
- **Transition:** 200 USDT auto-allocated to Tier 3 Pool; 400 USDT withdrawable net profit.

#### 3. 200 USDT Progression Pool
- **Entry Fee:** 200 USDT.
- **Depth 1 Payout (3 nodes):** 40 USDT per fill = 120 USDT.
- **Depth 2 Payout (9 nodes):** 120 USDT per fill = 1,080 USDT.
- **Cycle Total Yield:** 1,200 USDT.
- **Re-entry Loop:** 200 USDT auto-recycled into 200 USDT Pool; 1,000 USDT net withdrawable profit per loop.

---

### 4.3 State Machine Transition Specification

```
   [UNINITIALIZED]
          |
          | registerUser(referrer) [30 USDT]
          v
   [ACTIVE_FOUNDATIONAL]
          |
          | fillLevel7() && meetsQualificationCriteria()
          v
   [QUALIFIED_POOL_50]
          |
          | enterPool(POOL_50) [50 USDT]
          v
   [ACTIVE_POOL_50]
          |
          | completePool(12 nodes)
          v
   [QUALIFIED_POOL_100]
          |
          | enterPool(POOL_100) [100 USDT]
          v
   [ACTIVE_POOL_100]
          |
          | completePool(12 nodes)
          v
   [QUALIFIED_POOL_200]
          |
          | enterPool(POOL_200) [200 USDT]
          v
   [ACTIVE_POOL_200] <----+
          |              | Auto-Recycle
          | completePool | (200 USDT)
          +--------------+
```

#### State Transition Rules:
1. `UNINITIALIZED -> ACTIVE_FOUNDATIONAL`: Triggered when `msg.sender` transfers 30 USDT to `AltimaCore`.
2. `ACTIVE_FOUNDATIONAL -> QUALIFIED_POOL_50`: Triggered when member completes node allocations in Level 7 of the foundational matrix.
3. `QUALIFIED_POOL_50 -> ACTIVE_POOL_50`: Execution of `PoolEngine.enterPool(POOL_50)`.
4. `ACTIVE_POOL_50 -> QUALIFIED_POOL_100`: Reaching 12 downline node fills in Pool 50.
5. `QUALIFIED_POOL_100 -> ACTIVE_POOL_100`: Auto-transition or user approval to deduct 100 USDT entry fee into Pool 100.
6. `ACTIVE_POOL_100 -> QUALIFIED_POOL_200`: Reaching 12 downline node fills in Pool 100.
7. `QUALIFIED_POOL_200 -> ACTIVE_POOL_200`: Auto-transition into Tier 3 Pool.
8. `ACTIVE_POOL_200 -> ACTIVE_POOL_200`: Permanent cyclical loop upon completion of Tier 3 matrix.

---

### 4.4 Financial Safeguards & Audit Verification Rules

1. **Reentrancy Protection:** All financial state modifications use OpenZeppelin `ReentrancyGuard` with Checks-Effects-Interactions design pattern.
2. **Zero Insolvent State Guarantee:** The total payout across direct bonuses, level depths, and rebirth reserves is mathematically clamped to exactly 100% of the activation fee.
3. **P2P Push-Withdraw Balance Pattern:** Funds are routed into user-dedicated balance mappings (`withdrawableBalance[user]`), enabling pull-withdrawals to avoid DoS attacks.
