# PRD: Smart Inventory System with Intelligent Stock Analysis

| | |
|---|---|
| **Author** | Naura Aulia |
| **Status** | Shipped (v1, thesis delivery 2026) |
| **Stakeholders** | Director, General Manager, Technical Manager, Operations Manager, Finance, Warehouse Admin, IT Admin |
| **Context** | Project-based telecom infrastructure contractor headquartered in Palembang, with most suppliers based in Jakarta |

---

## 1. Problem

Stock is tracked in Excel as **numbers only**. The spreadsheet cannot tell whether "50 m of cable in stock" is one usable roll or several short, damaged leftovers from past projects.

From stakeholder interviews:

- **Stock says "available", but the material doesn't fit the job.** Warehouse staff have to physically check the warehouse before every urgent request.
- **Shortages are found too late.** Gaps only surface when the project team asks for material. Restocking from Jakarta takes days, so the company must either delay the project or pay for emergency procurement with express shipping to remote sites.
- **Leftover material piles up unnoticed.** Material returned from projects is reusable, but nobody tracks it. It turns into dead stock while the company keeps buying new.
- **Records don't match reality,** so managers don't fully trust the stock data.

**Why rules alone don't solve it:** leftover material comes in endless combinations of length, condition, and age, often described in free text ("minor defect", "returned from site X, don't splice"). Encoding every case in if-else logic is brittle. Reading that context and explaining a choice is something an LLM does well, as long as the numbers stay with the system.

## 2. Goals and non-goals

**Goals**
1. Give every role one centralized, real-time source of truth for stock.
2. Before every project, recommend which **specific pieces** to use and what must be purchased, with the reasoning shown.
3. Surface idle material automatically so it gets reused, sold, or returned before it becomes a loss.
4. Keep humans accountable: AI recommends, people decide.

**Non-goals (v1)**
- Time-series demand forecasting, safety stock, or reorder point. Stock is project-based, so the system plans per project instead.
- Automatic purchasing. Every purchase request goes through human approval.
- Finance, payroll, and fixed-asset management.

## 3. Users

| Role | Main job in the system | Key pain today |
|---|---|---|
| **Technical Manager** | Defines project material needs (from templates) and technical notes | Can't tell if stock actually fits the spec |
| **Warehouse Admin** | Validates AI matching, books material, raises purchase requests, records inbound/outbound | Manual physical checks before every request |
| **Operations Manager** | Approves purchase requests, decides on dead-stock follow-up | No early warning, no view of idle stock |
| **Finance** | Second-level approval of purchase requests | Emergency buys at express cost |
| **General Manager** | Monitors dashboards and reports | No reliable overview |
| **IT Admin** | Manages users, roles, and activity logs | n/a |

There is also a **system actor**, Gemini (the LLM), which runs AI matching, drafts purchase requests on shortage, and analyzes material aging in the background. In total, the design covers **7 actors and 26 use cases**. The Director and warehouse floor staff did not get system roles, but they took part in evaluating the AI (see section 8).

## 4. User stories

- As a **Technical Manager**, I want to choose a project template (e.g., tower installation) and adjust quantities, so that I don't rebuild the material list from scratch for every project.
- As a **Technical Manager**, I want to add technical notes such as "cable must not be spliced", so that recommendations respect field constraints.
- As a **Warehouse Admin**, I want the system to tell me which exact pieces to take and from which rack, with a reason for each, so that I don't have to check the warehouse physically.
- As a **Warehouse Admin**, I want shortages turned into a draft purchase request with urgency and lead time, so that I can act before the project start date.
- As an **Operations Manager**, I want a list of idle materials with a suggested action, so that I can free up capital and warehouse space.
- As **Finance**, I want to approve purchase requests after Operations, so that spending is controlled at two levels.

## 5. Solution overview

A role-based web app covering the full material flow:

**Project template → AI material matching → human validation → booking or purchase request → multi-level approval → inbound → outbound → AI dead-stock review**

Two AI modules sit on top of a deterministic core.

### 5.1 AI Material Matching

- **Trigger:** a project's material needs are submitted.
- **Input:** project data and start date, required materials, available stock, individual material pieces (length or quantity, age, source, condition, rack location), supplier lead times, and the Technical Manager's notes.
- **Output:** one of three actions per material:

| Action | When |
|---|---|
| `full_booking` | Stock fully covers the need |
| `partial` | Stock covers part of it; the rest becomes a purchase request |
| `full_pr` | No suitable stock; the full need becomes a purchase request |

  Plus the selected piece IDs, a reason per piece, and (when short) a purchase recommendation with urgency, normal vs. express mode, and the risk if delayed.
- **Piece selection prefers** available pieces that fit the required length, older stock first, good condition, and compliance with the technical notes.
- **Result:** a *Pending* task for the Warehouse Admin to approve or reject. Nothing executes automatically.

### 5.2 AI Dead Stock Sweeper

- **Trigger:** runs automatically after every outbound.
- **Input per material:** days since last use, age of the oldest available piece, usage over the last 90 days, and number of pieces idle for 120+ days.
- **Output:** a recommendation per material, prioritized high, medium, or low:

| Recommendation | Rule of thumb (deterministic fallback) |
|---|---|
| `monitor` | Low movement, keep watching |
| `jual_murah` (sell at discount) | Idle for about 120+ days |
| `clearance` | Idle for about 180+ days, still moving slightly |
| `return_distributor` | Idle for about 180+ days, almost no usage |
| `buang` (scrap) | Not usable, decided after validation |

- **Result:** a screening task for the Operations Manager to validate before any status change.

## 6. Functional requirements

Grouped by workflow. Every requirement traces back to the 26 use cases (UC01–UC26) in the system design.

**A. Access and administration**

| # | Requirement | Role | Use case |
|---|---|---|---|
| F1 | Log in and log out securely, with access based on role | All human roles | UC02, UC26 |
| F2 | Create, view, edit, and delete user accounts and their roles | IT Admin | UC01 |
| F3 | View activity logs of user operations | IT Admin | UC07 |

**B. Master data**

| # | Requirement | Role | Use case |
|---|---|---|---|
| F4 | Manage the material catalog (SKU, attributes, units) | Warehouse Admin | UC03 |
| F5 | Manage shelf and rack locations | Warehouse Admin | UC04 |
| F6 | Manage suppliers, including lead time | Warehouse Admin | UC05 |
| F7 | View, filter, and move stock between racks | Warehouse Admin | UC08 |

**C. Project planning and AI matching**

| # | Requirement | Role | Use case |
|---|---|---|---|
| F8 | Manage project templates with standard material lists (e.g., tower installation, network) | Technical Manager | UC06 |
| F9 | Enter project needs from a template, adjust quantities, add non-standard items and technical notes; this triggers AI matching | Technical Manager | UC10 |
| F10 | Compare project needs with real-time stock, then recommend `full_booking`, `partial`, or `full_pr` with specific pieces | Gemini (system) | UC11 |
| F11 | Review and approve or reject the AI matching recommendation | Warehouse Admin | UC12 |
| F12 | Handle follow-up tasks that are not approved directly (manual inbound allocation, choosing a specific piece, changing quantities, re-allocation) | Warehouse Admin | UC13 |
| F13 | Reserve (book) the approved stock so other projects can't take it | System | UC14 |

**D. Purchasing**

| # | Requirement | Role | Use case |
|---|---|---|---|
| F14 | Automatically create a draft purchase request for any shortage | System | UC15 |
| F15 | Review and adjust the draft, then formally submit the purchase request | Warehouse Admin | UC16, UC17 |
| F16 | Approve purchase requests in two levels: Operations Manager, then Finance | Operations Manager, Finance | UC18 |

**E. Warehouse movement**

| # | Requirement | Role | Use case |
|---|---|---|---|
| F17 | Record inbound deliveries from suppliers, based on the delivery note | Warehouse Admin | UC19 |
| F18 | Confirm outbound to project sites, which deducts stock and triggers the dead-stock analysis | Warehouse Admin, Technical Manager | UC20 |
| F19 | Record leftover material returned from projects, with a description of its physical condition | Warehouse Admin | UC21 |

**F. Dead stock**

| # | Requirement | Role | Use case |
|---|---|---|---|
| F20 | Analyze idle material after each outbound and recommend a follow-up action | Gemini (system) | UC22 |
| F21 | Review the dead-stock recommendations | Operations Manager | UC23 |
| F22 | Officially change a material's status to dead stock after validation | Operations Manager | UC24 |

**G. Monitoring and reporting**

| # | Requirement | Role | Use case |
|---|---|---|---|
| F23 | Real-time dashboard of stock movement, purchase request status, and AI results, filtered by role | All human roles | UC09 |
| F24 | Download inventory and AI analysis reports as PDF or Excel | Operations Manager, Finance, Technical Manager, Warehouse Admin | UC25 |

## 7. AI guardrails (non-functional)

The guiding principle: **the system owns the numbers, and the AI owns the explanation.**

| Risk | Mitigation |
|---|---|
| Hallucinated pieces or quantities | Real DB data is injected as context; every `piece_id` and quantity is validated in Laravel against actual stock |
| Unparseable output | JSON-only response (`responseMimeType: application/json`), fixed schema, enumerated actions, temperature 0.2 |
| Invalid response | Automatic retry with a shorter, stricter prompt |
| API down, quota, or timeout | Deterministic local fallback (FIFO matching; rule-based dead-stock scoring) so the workflow never blocks |
| Token cost | Context capped to the 30 most relevant pieces |
| Wrong decision executed | Human-in-the-loop: every recommendation becomes a task that must be approved |
| Slow UI | AI calls run on background queues; results are stored as JSONB |

Other non-functional requirements: web-based, role-based access control, and a Laravel 11, FilamentPHP, and PostgreSQL stack.

## 8. Success metrics

**Validated during the project**

| Metric | Method | Result |
|---|---|---|
| Functional correctness | Black-box testing of 26 features | 209 of 209 scenarios passed |
| AI feasibility (overall) | Expert judgment: 7 internal users (Director, GM, Technical Manager, warehouse staff), 22 statements, 5-point scale | **94.81%** (730/770), rated "very feasible" |
| AI distinguishes full, partial, and purchase request correctly | Same survey | 100% |
| AI only references pieces that exist in stock | Same survey | 97.14% |
| AI reasons are understandable | Same survey | 97.14% |
| Weakest area: relevance to the specific project need | Same survey | 82.86%, the first thing to improve |
| Trust in AI as a basis for booking or dead-stock decisions | Same survey | 88.57%, so building trust is the second priority |

**Proposed for production (not yet measured)**
- Number of emergency purchases per month
- Time from material request to booking confirmation
- Share of AI recommendations approved without edits
- Value of idle stock older than 120 days, and how much of it gets reused or cleared

## 9. Delivery approach

Built with **Rapid Application Development (RAD)**:
1. **Requirements planning:** interviews, current-process analysis, PIECES and SWOT analysis
2. **User design:** use case, activity, sequence, and class diagrams; database, UI, and AI logic design
3. **Construction:** iterative prototypes reviewed with users, including several rounds of prompt refinement
4. **Cutover:** black-box testing and expert judgment

## 10. Risks and open questions

- **Data quality:** recommendations are only as good as the condition notes staff enter. *Next step:* structured condition fields and photo uploads.
- **Recommendation relevance** scored lowest (82.86%). *Next step:* collect approve and reject reasons on tasks and feed them back into the prompts.
- **User trust** (88.57%) lags behind perceived accuracy. *Next step:* show a track record on each recommendation (e.g., "similar suggestions approved 9/10 times").
- **Vendor dependency** on a single LLM provider. The local fallback covers outages, and the service layer allows swapping models.
- **Adoption:** moving from Excel requires training; role-specific dashboards help reduce friction.
