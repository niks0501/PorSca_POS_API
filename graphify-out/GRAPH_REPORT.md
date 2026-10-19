# Graph Report - PorSca_POS_API  (2026-10-17)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 854 nodes · 2017 edges · 74 communities (31 shown, 43 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 53 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `85e3a62c`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Community 0
- Community 1
- Community 2
- Community 3
- Community 4
- Community 5
- Community 6
- Community 7
- Community 8
- Community 9
- Community 10
- Community 11
- Community 12
- Community 13
- Community 14
- Community 15
- Community 16
- Community 17
- Community 18
- Community 19
- Community 20
- Community 21
- Community 22
- Community 23
- Community 24
- Community 25
- Community 26
- Community 27
- Community 28
- Community 29
- Community 30
- Community 31
- Community 32
- Community 33
- Community 34
- Community 35
- Community 36
- Community 37
- Community 38
- Community 39
- Community 40
- Community 41
- Community 42
- Community 43
- Community 44
- Community 45
- Community 46
- Community 47
- Community 48
- Community 49
- Community 50
- Community 51
- Community 54
- Community 55
- Community 56
- Community 57
- Community 58
- Community 59
- Community 60
- Community 61
- Community 62
- Community 63
- Community 64
- Community 66
- Community 67

## God Nodes (most connected - your core abstractions)
1. `Payment` - 87 edges
2. `User` - 55 edges
3. `Checkout` - 45 edges
4. `Product` - 42 edges
5. `CheckoutAuthorityTest` - 41 edges
6. `TestCase` - 39 edges
7. `ApiException` - 31 edges
8. `PaymentLifecycleTest` - 29 edges
9. `CheckoutAuthorityController` - 28 edges
10. `PaymentGateway` - 27 edges

## Surprising Connections (you probably didn't know these)
- `{closure#2}()` --references_constant--> `Payment`  [EXTRACTED]
  tests/Support/concurrent-settlement.php → app/Models/Payment.php
- `{closure#3}()` --references--> `Payment`  [EXTRACTED]
  tests/Support/concurrent-settlement.php → app/Models/Payment.php
- `CheckoutAuthorityTest` --references--> `User`  [EXTRACTED]
  tests/Feature/CheckoutAuthorityTest.php → app/Models/User.php
- `{closure#6}()` --references--> `ApiException`  [EXTRACTED]
  bootstrap/app.php → app/Exceptions/ApiException.php
- `CheckoutTestGateway` --implements--> `PaymentGateway`  [EXTRACTED]
  tests/Feature/CheckoutAuthorityTest.php → app/Contracts/PaymentGateway.php

## Import Cycles
- None detected.

## Communities (74 total, 43 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.07
Nodes (7): {closure#3}(), TransactionController, CheckoutEvent, CheckoutOperation, PaymentItem, SaleItem, Transaction

### Community 1 - "Community 1"
Cohesion: 0.09
Nodes (8): {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), ProductController, Product, {closure#2}(), CheckoutAuthorityConcurrencyTest

### Community 3 - "Community 3"
Cohesion: 0.10
Nodes (6): PaymentGatewayException, AppServiceProvider, {closure#1}(), PayMongoSandboxGateway, CheckoutTestGateway, PayMongoSandboxGatewayTest

### Community 5 - "Community 5"
Cohesion: 0.18
Nodes (8): {closure#4}(), CheckoutAuthority, {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#7}()

### Community 6 - "Community 6"
Cohesion: 0.11
Nodes (3): AdminUserSeeder, DatabaseSeeder, QaResetTest

### Community 7 - "Community 7"
Cohesion: 0.15
Nodes (4): {closure#2}(), InventoryController, Inventory, ProductManagementTest

### Community 8 - "Community 8"
Cohesion: 0.12
Nodes (3): InventoryFactory, ProductFactory, UserFactory

### Community 9 - "Community 9"
Cohesion: 0.13
Nodes (6): {closure#3}(), {closure#5}(), {closure#6}(), {closure#7}(), ReconciliationCase, CheckoutReconciliation

### Community 10 - "Community 10"
Cohesion: 0.16
Nodes (4): {closure#1}(), {closure#2}(), UserController, User

### Community 11 - "Community 11"
Cohesion: 0.19
Nodes (5): ApiException, IdempotencyConflict, InsufficientCash, {closure#1}(), {closure#1}()

### Community 13 - "Community 13"
Cohesion: 0.20
Nodes (3): EnsureActiveUser, EnsureCheckoutContract, EnsureRole

### Community 15 - "Community 15"
Cohesion: 0.18
Nodes (4): PaymentGateway, PaymentController, PaymentSettlementService, WebhookService

### Community 16 - "Community 16"
Cohesion: 0.20
Nodes (5): {closure#1}(), Payment, Sale, {closure#1}(), {closure#1}()

### Community 17 - "Community 17"
Cohesion: 0.15
Nodes (8): {closure#1}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}(), {closure#9}()

### Community 25 - "Community 25"
Cohesion: 0.29
Nodes (8): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), {closure#8}()

### Community 26 - "Community 26"
Cohesion: 0.21
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}()

### Community 27 - "Community 27"
Cohesion: 0.33
Nodes (3): CashSaleService, CheckoutOutcomes, ReservedStock

### Community 32 - "Community 32"
Cohesion: 0.20
Nodes (10): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+2 more)

### Community 37 - "Community 37"
Cohesion: 0.22
Nodes (8): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type

### Community 38 - "Community 38"
Cohesion: 0.25
Nodes (8): require-dev, laravel/boost, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 40 - "Community 40"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 43 - "Community 43"
Cohesion: 0.33
Nodes (6): require, fakerphp/faker, laravel/framework, laravel/sanctum, laravel/tinker, php

### Community 44 - "Community 44"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 45 - "Community 45"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 47 - "Community 47"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 48 - "Community 48"
Cohesion: 0.60
Nodes (5): {closure#1}(), {closure#2}(), collation(), down(), up()

### Community 49 - "Community 49"
Cohesion: 0.33
Nodes (5): php, npx, @upstash/context7-mcp, context7, laravel-boost

### Community 51 - "Community 51"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 64 - "Community 64"
Cohesion: 0.50
Nodes (3): npx, @upstash/context7-mcp, context7

### Community 66 - "Community 66"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 67 - "Community 67"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps
- **5 isolated node(s):** `php`, `npx`, `@upstash/context7-mcp`, `npx`, `@upstash/context7-mcp`
  These have ≤1 connection - possible missing edges. (Counts symbols only; 235 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **43 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `TestCase` connect `Community 29` to `Community 0`, `Community 1`, `Community 2`, `Community 3`, `Community 4`, `Community 6`, `Community 7`, `Community 14`, `Community 15`, `Community 18`, `Community 21`, `Community 26`, `Community 28`, `Community 30`, `Community 33`, `Community 34`, `Community 35`, `Community 36`, `Community 41`, `Community 42`, `Community 50`?**
  _High betweenness centrality (0.145) - this node is a cross-community bridge._
- **What connects `php`, `npx`, `@upstash/context7-mcp` to the rest of the system?**
  _5 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Community 0` be split into smaller, more focused modules?**
  _Cohesion score 0.0696969696969697 - nodes in this community are weakly interconnected._
- **Why does `Payment` connect `Community 16` to `Community 0`, `Community 1`, `Community 3`, `Community 4`, `Community 5`, `Community 9`, `Community 11`, `Community 12`, `Community 13`, `Community 14`, `Community 15`, `Community 18`, `Community 21`, `Community 24`, `Community 26`, `Community 27`, `Community 28`, `Community 31`?**
  _High betweenness centrality (0.129) - this node is a cross-community bridge._
- **Should `Community 1` be split into smaller, more focused modules?**
  _Cohesion score 0.08859357696567 - nodes in this community are weakly interconnected._
- **Why does `User` connect `Community 10` to `Community 0`, `Community 1`, `Community 2`, `Community 3`, `Community 5`, `Community 6`, `Community 7`, `Community 8`, `Community 9`, `Community 15`, `Community 18`, `Community 20`, `Community 21`, `Community 27`, `Community 28`, `Community 29`, `Community 30`, `Community 31`, `Community 36`?**
  _High betweenness centrality (0.110) - this node is a cross-community bridge._
- **Should `Community 3` be split into smaller, more focused modules?**
  _Cohesion score 0.09523809523809523 - nodes in this community are weakly interconnected._