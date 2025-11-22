# Task: Test Fixture Refactoring and Test Utilities Improvement

## Context

The test suite includes various fixtures and helper utilities:
- `tests/Fixture/` - factories and fixtures
  - `OrderFactory` - creates test orders
  - `CurrencyScenarioFactory` - creates currency scenarios
  - `FeePolicyFactory` - creates fee policies
  - `BottleneckOrderBookFactory` - creates bottleneck scenarios
  - `PathFinderEdgeCaseFixtures` - edge case order books
- `tests/Support/` - test utilities
  - `DecimalMath` - decimal math helper for tests
  - `InfectionIterationLimiter` - adjusts property test iterations
- `tests/Application/Support/` - application-level test support
  - `DecimalFactory` - decimal value factory
  - `Generator/` - property test generators
  - `Harness/` - test harnesses

This task focuses on:
- Improving fixture quality and consistency
- Reducing test duplication
- Making tests more maintainable
- Improving test utilities

## Problem

**Test fixture concerns:**

1. **Fixture duplication**:
   - Are there test orders/scenarios duplicated across test files?
   - Can they be centralized?
   - Are fixture factories comprehensive enough?

2. **Fixture quality**:
   - Are fixtures realistic?
   - Do they cover edge cases?
   - Are they well-documented?
   - Are they easy to use?

3. **Factory coverage**:
   - `OrderFactory` - sufficient? (appears to exist)
   - `Money` factory - needed?
   - `ExchangeRate` factory - needed?
   - `OrderBook` factory - BottleneckOrderBookFactory exists, others needed?

4. **Test utility organization**:
   - Is `tests/Support/` the right place for utilities?
   - Should there be more structure? (e.g., Support/Math/, Support/Generators/)
   - Are utilities well-named and discoverable?

5. **Builder pattern usage**:
   - Are factories using builder pattern where appropriate?
   - Example: `OrderFactory::buy()->withFee()->build()`
   - Or simple factory methods sufficient?

6. **Test data organization**:
   - Are large test data sets externalized? (JSON, CSV, etc.)
   - Or embedded in test code?
   - Should complex scenarios be in separate files?

7. **Assertion helpers**:
   - Are there custom assertions? (MoneyAssertions.php exists ✓)
   - Are they comprehensive?
   - Should there be more?

8. **Test base classes**:
   - Are there test base classes with common setup?
   - Should there be?
   - Or is composition preferred?

## Proposed Changes

### 1. Audit fixture usage across test suite

**Search for duplicated test data**:
```bash
grep -r "new Order" tests/ | wc -l
grep -r "Money::fromString" tests/ | wc -l
grep -r "OrderFactory::" tests/ | wc -l
```

**Identify patterns**:
- Common order scenarios (USD/BTC, EUR/USD, etc.)
- Common amounts (100.00, 1000.00, etc.)
- Common tolerance values (0.05, 0.10, etc.)

**Decide what should be centralized**

### 2. Enhance OrderFactory

**Current capabilities** (infer from usage):
- Create buy/sell orders
- Various currencies and amounts

**Consider adding**:
```php
OrderFactory::buy($base, $quote, $min, $max, $rate, $baseScale, $quoteScale)
OrderFactory::sell($base, $quote, $min, $max, $rate, $baseScale, $quoteScale)
```

**Builder pattern**:
```php
OrderFactory::create()
    ->buy()
    ->pair('USD', 'BTC')
    ->bounds('10.00', '1000.00')
    ->rate('0.00003')
    ->withFee(FeePolicy::percentage('0.001'))
    ->build();
```

**Evaluate** if builder adds value or complicates

### 3. Create additional factories

**MoneyFactory** (if not exists):
```php
MoneyFactory::usd('100.00')
MoneyFactory::btc('0.001')
MoneyFactory::create('EUR', '50.00', 2)
```

**ExchangeRateFactory**:
```php
ExchangeRateFactory::create('USD', 'BTC', '0.00003', 8)
```

**OrderBookFactory** (expand beyond Bottleneck):
```php
OrderBookFactory::simple() // Few orders, direct paths
OrderBookFactory::complex() // Many orders, multi-hop
OrderBookFactory::dense() // High fan-out
OrderBookFactory::sparse() // Few connections
OrderBookFactory::withOrders([...])
```

**PathSearchConfigFactory**:
```php
ConfigFactory::default()
ConfigFactory::strict() // Tight tolerance, low guards
ConfigFactory::permissive() // Wide tolerance, high guards
ConfigFactory::lowLatency() // Optimized for speed
```

### 4. Reorganize test utilities

**Current structure**:
```
tests/Support/
  - DecimalMath.php
  - InfectionIterationLimiter.php
tests/Application/Support/
  - DecimalFactory.php
  - Generator/
  - Harness/
```

**Consider**:
```
tests/Support/
  - Math/
    - DecimalMath.php
    - DecimalFactory.php
  - Generators/
    - [move from Application/Support/Generator]
  - Assertions/
    - MoneyAssertions.php (move from Domain/ValueObject/)
    - [other assertions]
  - Harness/
    - [move from Application/Support/Harness]
  - Testing/
    - InfectionIterationLimiter.php
```

**Evaluate** if reorganization adds value

### 5. Improve test data organization

**For large test data sets**:
- Create `tests/Fixture/data/` directory
- Store complex scenarios as JSON/YAML
- Load in tests with helpers

**Example**:
```json
// tests/Fixture/data/complex-triangular-arbitrage.json
{
  "description": "Complex triangular arbitrage scenario with 50 orders",
  "orders": [...]
}
```

**Loader**:
```php
FixtureLoader::loadOrderBook('complex-triangular-arbitrage.json')
```

**Evaluate** if external data files add value or complicate

### 6. Expand custom assertions

**Current** (MoneyAssertions.php):
- Custom assertions for Money equality, etc.

**Consider adding**:
```php
PathAssertions::assertPathValid($path, $constraints)
PathAssertions::assertOptimalPath($path, $allPaths)

OrderBookAssertions::assertOrderBookValid($orderBook)
OrderBookAssertions::assertContainsOrder($orderBook, $order)

GraphAssertions::assertGraphComplete($graph, $orders)
GraphAssertions::assertNodesConnected($graph, $from, $to)

SearchOutcomeAssertions::assertHasPaths($outcome)
SearchOutcomeAssertions::assertGuardsNotExceeded($outcome)
```

**Implement** assertions that are used frequently

### 7. Create test trait for common setup

**If many tests share setup**:
```php
trait PathFinderTestSetup
{
    protected PathFinderService $pathFinder;
    protected OrderBook $orderBook;
    
    protected function setUpPathFinder(): void
    {
        $this->pathFinder = new PathFinderService(new GraphBuilder());
        $this->orderBook = OrderBookFactory::simple();
    }
}
```

**Use trait**:
```php
class SomePathFinderTest extends TestCase
{
    use PathFinderTestSetup;
    
    protected function setUp(): void
    {
        $this->setUpPathFinder();
    }
}
```

**Evaluate** if traits reduce duplication significantly

### 8. Document test utilities

**Create tests/README.md**:

Explain:
- Test organization
- Fixture factories available
- Test utilities and helpers
- Custom assertions
- How to add new fixtures
- Best practices for tests

**Document each factory** with examples

### 9. Add fixture validation

**Ensure fixtures are valid**:
```php
final class FixtureValidator
{
    public static function validateOrder(Order $order): void
    {
        // Ensure fixture orders are internally consistent
    }
    
    public static function validateOrderBook(OrderBook $orderBook): void
    {
        // Ensure fixture order books are realistic
    }
}
```

**Call in tests** or fixtures themselves:
```php
public static function create(): Order
{
    $order = new Order(...);
    FixtureValidator::validateOrder($order);
    return $order;
}
```

**Helps catch fixture bugs early**

### 10. Add fixture examples to documentation

**In docs/testing.md** or similar:

Show:
- How to use OrderFactory
- How to create custom test scenarios
- How to use custom assertions
- Best practices for test fixtures

**Makes it easier for contributors to write consistent tests**

## Dependencies

- Complements task 0006 (test coverage) - better fixtures enable better tests
- Informs task 0007 (documentation) - test utilities documentation

## Effort Estimate

**M** (0.5-1 day)
- Fixture usage audit: 1-2 hours
- OrderFactory enhancement: 1-2 hours
- Additional factories: 2-3 hours
- Test utility reorganization: 1-2 hours
- Test data organization: 1 hour
- Custom assertions: 1-2 hours
- Test traits: 1 hour
- Documentation: 2 hours
- Fixture validation: 1 hour
- Example documentation: 1 hour

## Risks / Considerations

- **Over-engineering**: Not every test needs factories and builders
- **Indirection**: Too many layers of abstraction make tests hard to understand
- **Maintenance**: More test utilities = more code to maintain
- **Discoverability**: Tests should be understandable without digging through utilities

**Balance**: 
- Use factories for common, complex scenarios
- Keep simple tests simple
- Document test utilities well
- Prefer explicitness over DRY in tests

## Definition of Done

- [ ] Fixture usage audit completed
- [ ] Duplicated test data identified and centralized (if significant)
- [ ] OrderFactory enhanced (if needed)
- [ ] Additional factories created (Money, ExchangeRate, OrderBook, Config)
- [ ] Test utilities organized logically
- [ ] Large test data sets externalized (if beneficial)
- [ ] Custom assertions expanded
- [ ] Test traits created (if reduce duplication significantly)
- [ ] tests/README.md created documenting test utilities
- [ ] Fixture factories documented with examples
- [ ] Fixture validation added (optional)
- [ ] docs/testing.md created with fixture examples
- [ ] All tests still pass
- [ ] Tests are more maintainable and less duplicative

**Priority:** P3 – Nice to have

