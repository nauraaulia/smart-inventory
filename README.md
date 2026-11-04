# Smart Inventory System with AI Stock Analysis

> A web-based inventory system for a telecom infrastructure contractor that uses an LLM (Google Gemini) to match project material needs against real warehouse stock and to flag idle "dead stock", with every AI recommendation validated by a human before it is executed.

![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![FilamentPHP](https://img.shields.io/badge/Filament-5-FDAE4B)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-JSONB-4169E1?logo=postgresql&logoColor=white)
![Gemini](https://img.shields.io/badge/Google%20Gemini-API-8E75B2?logo=googlegemini&logoColor=white)

Built as my undergraduate thesis (Information Systems, UIN Syarif Hidayatullah Jakarta, 2026), based on a real case study at a project-based contractor.

<!-- Add 1–2 screenshots here, e.g. the dashboard and the AI matching result:
![Dashboard](docs/screenshots/dashboard.png)
![AI Material Matching](docs/screenshots/ai-matching.png)
-->

---

## The problem

The company's existing inventory records only tracked **quantities**, with no context about the material itself (condition, size, age, remaining length of a cut piece). That caused two recurring issues:

- **Emergency procurement.** Stock looked available on paper, but the physical pieces didn't actually fit the project's spec, so the team had to buy again at the last minute and pay extra mobilization costs to send materials to out-of-town sites.
- **Unidentified dead stock.** Leftover material returned from projects sat in the warehouse for months without anyone noticing, tying up capital and space.

## The solution

A role-based inventory system covering the full material flow (project template → stock check → purchase request → approval → inbound → outbound → leftover review), with an **Intelligent Stock Analysis** layer made of two AI modules:

| Module | What it does | Output |
|---|---|---|
| **AI Material Matching** | Compares a project's material requirements with actual pieces in the warehouse (condition, size, rack location, supplier lead time) and explains *which* pieces to use and why | `full_booking`, `partial`, or `full_pr` (purchase request) |
| **AI Dead Stock Sweeper** | Runs automatically after each outbound and reviews idle material by stock age and 90-day usage | `monitor`, `clearance`, `jual_murah`, `return_distributor`, or `buang` |

**Design principle:** quantities and validation are always calculated **deterministically** by the system. The LLM only reasons over the textual context and writes the recommendation. Nothing is executed until an authorized user approves it (**human-in-the-loop**).

## Key features

- Role-based access for 6 roles: IT Admin, Technical Manager, Warehouse Admin, Operations Manager, Finance, General Manager
- Project templates with standard material lists (e.g., tower installation, network), plus manual adjustments
- AI material matching with a narrative explanation per decision
- Purchase request flow with multi-level approval (Operations → Finance)
- Inbound (supplier delivery and project returns with condition notes) and outbound
- Automatic AI dead-stock review after every outbound
- Real-time dashboard, activity logs, and printable reports

## How the AI layer is made reliable

LLMs can hallucinate, so the integration uses several layers of defense:

1. **Generation config:** low temperature (0.2) and `responseMimeType: application/json`
2. **System prompt:** strict JSON-only output and a fixed set of allowed actions (enumerated output)
3. **Context grounding:** real database data is injected into the prompt; the top 30 most relevant pieces are compacted to save tokens
4. **Application-layer validation:** Laravel checks the JSON schema, that every `piece_id` actually exists, and that allocations are consistent
5. **Retry:** if the response is invalid, it is resent with a shorter prompt
6. **Local fallback:** if the AI is unavailable, a deterministic FIFO allocation runs instead

AI analysis runs on **Laravel queues** so the UI stays responsive, and results are stored as **JSONB** in PostgreSQL.

## Architecture

```
Project needs + stock + material pieces + conditions + suppliers
                         │
                         ▼
          Prompt engineering layer (service classes)
       BookingAIService · DeadstockAIService · GeminiService
                         │   (Laravel queue)
                         ▼
                   Google Gemini API
                         │   structured JSON
                         ▼
        Validation → task created for a human to approve
                         │
                         ▼
     Booking / purchase request / dead-stock follow-up
```

## Results

| Evaluation | Method | Result |
|---|---|---|
| Functional testing | Black-box testing of 26 features | **209/209** test scenarios passed |
| AI feature feasibility | Expert judgment by 7 internal company respondents | **94.81%** feasibility score |

## Development process

Built with **Rapid Application Development (RAD)**:

1. **Requirement planning:** stakeholder interviews, analysis of the current process, PIECES and SWOT analysis, 15 functional requirements
2. **User design:** use case, activity, sequence, and class diagrams, database design, UI design, and AI logic design
3. **Construction:** iterative prototyping with user feedback, including several iterations refining the AI prompts
4. **Cutover:** black-box testing and expert judgment

## Tech stack

PHP 8.3 · Laravel 11 · FilamentPHP · PostgreSQL · Google Gemini API · Laravel Queue · Laravel Herd · Git

## Running locally

```bash
git clone https://github.com/nauraaulia/smart-inventory.git
cd smart-inventory
composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate
# Fill in your DB credentials and your own Gemini API key in .env:
# GEMINI_API_KEY=your_key_here   (get one at https://aistudio.google.com/apikey)

php artisan migrate --seed
php artisan queue:work   # in a separate terminal, needed for AI analysis
php artisan serve
```

> Seeded data is dummy data for demo purposes. Company-specific data is not included.

## Author

**Naura Aulia** · Information Systems, UIN Syarif Hidayatullah Jakarta
[LinkedIn](https://www.linkedin.com/in/nauraaulia)