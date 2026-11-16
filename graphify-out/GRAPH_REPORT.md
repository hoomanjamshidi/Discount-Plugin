# Graph Report - plugin  (2026-11-16)

## Corpus Check
- 64 files · ~75,652 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 10 file(s) not represented in the graph (top: (none) 3, .conf 3, .ini 2)

## Summary
- 640 nodes · 1763 edges · 25 communities (21 shown, 4 thin omitted)
- Extraction: 92% EXTRACTED · 8% INFERRED · 0% AMBIGUOUS · INFERRED: 149 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- CLI & Campaign Model
- Campaign Entity
- Integration Test Harness
- Schedule Windows
- Plugin Bootstrap & Admin Mount
- App Shell & Routing
- Wizard & Category Picker
- Price Calculator
- Spark Chart
- Campaign Pages
- Countdown Delete Actions
- Schedule Step & Date Time
- Toast Notifications
- Animated Checkboxes
- Jalali Date Field
- Product Picker & Basket
- Shared UI Primitives
- Glide Select
- Status Mark
- Load Gauge
- CSS Scoping Build
- Worker Lock

## God Nodes (most connected - your core abstractions)
1. `Repository` - 44 edges
2. `Campaign` - 41 edges
3. `cn()` - 32 edges
4. `CampaignDetail()` - 25 edges
5. `ResourceGovernor` - 25 edges
6. `fa()` - 23 edges
7. `Engine` - 22 edges
8. `RestController` - 22 edges
9. `num()` - 21 edges
10. `CampaignService` - 20 edges

## Surprising Connections (you probably didn't know these)
- `FakeEnvironment` --inherits--> `Environment`  [EXTRACTED]
  tests/unit/FakeEnvironment.php → smart-bulk-discount/includes/Environment.php
- `{closure#1}()` --calls--> `RestController`  [INFERRED]
  smart-bulk-discount/includes/Plugin.php → smart-bulk-discount/includes/RestController.php
- `App()` --calls--> `CampaignDetail()`  [EXTRACTED]
  admin-app/src/app/App.tsx → admin-app/src/app/CampaignDetail.tsx
- `App()` --calls--> `DiscreteTabs()`  [EXTRACTED]
  admin-app/src/app/App.tsx → admin-app/src/components/discrete-tabs.tsx
- `CampaignDetail()` --calls--> `useToast()`  [EXTRACTED]
  admin-app/src/app/CampaignDetail.tsx → admin-app/src/app/Toaster.tsx

## Import Cycles
- None detected.

## Communities (25 total, 4 thin omitted)

### Community 0 - "CLI & Campaign Model"
Cohesion: 0.06
Nodes (6): Cli, Engine, Environment, Repository, ResourceGovernor, Watchdog

### Community 1 - "Campaign Entity"
Cohesion: 0.08
Nodes (5): Campaign, CampaignService, Queue, RestController, TargetResolver

### Community 2 - "Integration Test Harness"
Cohesion: 0.09
Nodes (32): app_password(), assert_applied(), assert_restored(), batch_stats(), check(), debug_log_fatals(), Fail, main() (+24 more)

### Community 3 - "Schedule Windows"
Cohesion: 0.06
Nodes (4): Schedule, FakeEnvironment, ResourceGovernorTest, ScheduleTest

### Community 4 - "Plugin Bootstrap & Admin Mount"
Cohesion: 0.05
Nodes (8): {closure#3}(), AdminPage, Installer, {closure#1}(), Plugin, PriceGuard, dsd_descendants(), dsd_eligible()

### Community 5 - "App Shell & Routing"
Cohesion: 0.13
Nodes (28): App(), hashFor(), Tab, View, viewFromHash(), CampaignList(), Row(), SettingsPanel() (+20 more)

### Community 6 - "Wizard & Category Picker"
Cohesion: 0.09
Nodes (26): CategoryPicker(), GLIDE, SetFn, SetOptFn, STEPS, StepShell(), StepTarget(), SwitchRow() (+18 more)

### Community 7 - "Price Calculator"
Cohesion: 0.08
Nodes (3): PriceCalculator, Settings, PriceCalculatorTest

### Community 8 - "Spark Chart"
Cohesion: 0.10
Nodes (23): AnimatedSparkChart(), AnimatedSparkChartProps, buildSparkPath(), formatCompact(), springIn, toFaDigits(), AnimatedSwitches(), fillSpring (+15 more)

### Community 9 - "Campaign Pages"
Cohesion: 0.21
Nodes (22): CampaignDetail(), Detail, FILTERS, Timeline(), CampaignCard(), Group(), SCENARIOS, campaignProgress() (+14 more)

### Community 10 - "Countdown Delete Actions"
Cohesion: 0.13
Nodes (15): Actions(), CHAR, DeleteButton(), DeleteButtonProps, LAYOUT, splitLabel(), toFaDigits(), SaveButton() (+7 more)

### Community 11 - "Schedule Step & Date Time"
Cohesion: 0.25
Nodes (16): WorkerLine(), DateTimeField(), Segmented(), guessTiming(), StepReview(), StepTiming(), validateStep(), ago() (+8 more)

### Community 12 - "Toast Notifications"
Cohesion: 0.17
Nodes (11): Ctx, Push, ToasterProvider(), KIND, springIn, springLayout, ToastCard(), ToastItem (+3 more)

### Community 13 - "Animated Checkboxes"
Cohesion: 0.17
Nodes (14): AnimatedCheckboxes(), BounceCheckbox(), BoxSize, boxSpring, BurstCheckboxProps, CheckMark(), CircleCheckbox(), CircleCheckboxProps (+6 more)

### Community 14 - "Jalali Date Field"
Cohesion: 0.23
Nodes (14): CalendarPanel(), CaptionChip(), DateField(), DateFieldProps, DayCell(), EmptyDayCell(), formatSelected(), MonthGrid() (+6 more)

### Community 15 - "Product Picker & Basket"
Cohesion: 0.25
Nodes (13): Items(), BasketPanel(), ProductPicker(), Thumb(), Btn(), Basket, BasketItem, load() (+5 more)

### Community 16 - "Shared UI Primitives"
Cohesion: 0.24
Nodes (11): Stat(), ChoiceCard(), Field(), TextInput(), StepDiscount(), formatNumber(), NumberTicker(), NumberTickerDemo() (+3 more)

### Community 17 - "Glide Select"
Cohesion: 0.22
Nodes (12): DEFAULT_OPTIONS, DEMO_OPTIONS, GlideSelect(), GlideSelectDemo(), GlideSelectOption, GlideSelectProps, norm(), Phase (+4 more)

### Community 18 - "Status Mark"
Cohesion: 0.25
Nodes (10): clamp01(), DEMO_ROWS, faNum(), MORPH, StatusMark(), StatusMarkDemo(), StatusMarkProps, StatusMarkStatus (+2 more)

### Community 19 - "Load Gauge"
Cohesion: 0.29
Nodes (9): clamp(), Grip, Live, onColor(), Sim, SloshGauge(), SloshGaugeDemo(), SloshGaugeProps (+1 more)

### Community 20 - "CSS Scoping Build"
Cohesion: 0.29
Nodes (3): css, kb, plugin

## Knowledge Gaps
- **4 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Environment` connect `CLI & Campaign Model` to `Schedule Windows`, `Plugin Bootstrap & Admin Mount`?**
  _High betweenness centrality (0.041) - this node is a cross-community bridge._
- **Are the 2 inferred relationships involving `Repository` (e.g. with `.boot()` and `.rebuild()`) actually correct?**
  _`Repository` has 2 INFERRED edges - model-reasoned connections that need verification._
- **Should `CLI & Campaign Model` be split into smaller, more focused modules?**
  _Cohesion score 0.05949312714776632 - nodes in this community are weakly interconnected._
- **Why does `Repository` connect `CLI & Campaign Model` to `Campaign Entity`, `Plugin Bootstrap & Admin Mount`?**
  _High betweenness centrality (0.034) - this node is a cross-community bridge._
- **Should `Campaign Entity` be split into smaller, more focused modules?**
  _Cohesion score 0.07960199004975124 - nodes in this community are weakly interconnected._
- **Why does `ResourceGovernor` connect `CLI & Campaign Model` to `Schedule Windows`?**
  _High betweenness centrality (0.030) - this node is a cross-community bridge._
- **Should `Integration Test Harness` be split into smaller, more focused modules?**
  _Cohesion score 0.09137529137529138 - nodes in this community are weakly interconnected._