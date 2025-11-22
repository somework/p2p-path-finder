# API Contracts

This document defines the JSON serialization contracts for all public API types that implement `JsonSerializable`. These contracts are stable as of version 1.0.0 and follow semantic versioning guarantees.

---

## Table of Contents

- [Version Compatibility](#version-compatibility)
- [Common Types](#common-types)
  - [Money](#money)
  - [MoneyMap](#moneymap)
- [Path Results](#path-results)
  - [PathResult](#pathresult)
  - [PathLeg](#pathleg)
  - [PathLegCollection](#pathlegcollection)
- [Search Results](#search-results)
  - [SearchOutcome](#searchoutcome)
  - [SearchGuardReport](#searchguardreport)
  - [PathResultSet](#pathresultset)

---

## Version Compatibility

**Current Version**: 1.0.0  
**Last Updated**: 2024

### Stability Guarantees

- **Field Additions**: New optional fields may be added in minor versions (1.x.0)
- **Field Removals**: Required fields will never be removed in minor versions
- **Type Changes**: Field types will never change in minor versions
- **Deprecation**: Deprecated fields will be marked and removed only in major versions

### Breaking Changes

Breaking changes to JSON contracts will only occur in major version releases (2.0.0, 3.0.0, etc.) and will be clearly documented in the changelog and upgrade guide.

---

## Common Types

### Money

**Class**: `SomeWork\P2PPathFinder\Domain\ValueObject\Money`

**Purpose**: Represents a monetary amount in a specific currency with decimal precision.

**JSON Structure**:

```json
{
  "currency": "USD",
  "amount": "100.50",
  "scale": 2
}
```

**Fields**:

| Field      | Type   | Required | Description                                    |
|------------|--------|----------|------------------------------------------------|
| `currency` | string | Yes      | ISO currency code (uppercase, e.g., "USD")     |
| `amount`   | string | Yes      | Decimal amount as numeric string               |
| `scale`    | int    | Yes      | Decimal places (0-18)                          |

**Notes**:
- The `amount` field is a string to preserve precision for very large or very precise numbers
- The `scale` indicates how many decimal places are significant
- Trailing zeros are preserved in the `amount` string

**Example**:

```json
{
  "currency": "EUR",
  "amount": "92.123456",
  "scale": 6
}
```

---

### MoneyMap

**Class**: `SomeWork\P2PPathFinder\Application\Result\MoneyMap`

**Purpose**: Immutable map of currency codes to Money objects, used for fee breakdowns.

**JSON Structure**:

```json
{
  "USD": {
    "currency": "USD",
    "amount": "1.50",
    "scale": 2
  },
  "EUR": {
    "currency": "EUR",
    "amount": "0.45",
    "scale": 2
  }
}
```

**Structure**:
- Object with currency codes as keys
- Each value is a [Money](#money) object
- Keys are sorted alphabetically
- Empty map is represented as `{}`

**Notes**:
- Keys always match the `currency` field of their corresponding value
- The map is always sorted by currency code for deterministic output

---

## Path Results

### PathResult

**Class**: `SomeWork\P2PPathFinder\Application\Result\PathResult`

**Purpose**: Aggregated representation of a discovered conversion path.

**JSON Structure**:

```json
{
  "totalSpent": {
    "currency": "USD",
    "amount": "100.00",
    "scale": 2
  },
  "totalReceived": {
    "currency": "EUR",
    "amount": "92.50",
    "scale": 2
  },
  "residualTolerance": "0.0123456789",
  "feeBreakdown": {
    "USD": {
      "currency": "USD",
      "amount": "0.50",
      "scale": 2
    },
    "EUR": {
      "currency": "EUR",
      "amount": "0.45",
      "scale": 2
    }
  },
  "legs": [
    {
      "from": "USD",
      "to": "GBP",
      "spent": {
        "currency": "USD",
        "amount": "100.00",
        "scale": 2
      },
      "received": {
        "currency": "GBP",
        "amount": "80.00",
        "scale": 2
      },
      "fees": {
        "GBP": {
          "currency": "GBP",
          "amount": "0.40",
          "scale": 2
        }
      }
    },
    {
      "from": "GBP",
      "to": "EUR",
      "spent": {
        "currency": "GBP",
        "amount": "79.60",
        "scale": 2
      },
      "received": {
        "currency": "EUR",
        "amount": "92.95",
        "scale": 2
      },
      "fees": {
        "EUR": {
          "currency": "EUR",
          "amount": "0.45",
          "scale": 2
        }
      }
    }
  ]
}
```

**Fields**:

| Field               | Type                        | Required | Description                                  |
|---------------------|-----------------------------|----------|----------------------------------------------|
| `totalSpent`        | [Money](#money)             | Yes      | Total source asset spent                     |
| `totalReceived`     | [Money](#money)             | Yes      | Total destination asset received             |
| `residualTolerance` | string                      | Yes      | Remaining tolerance as decimal string        |
| `feeBreakdown`      | [MoneyMap](#moneymap)       | Yes      | Total fees by currency (may be empty)        |
| `legs`              | array<[PathLeg](#pathleg)>  | Yes      | Individual conversion legs (may be empty)    |

**Notes**:
- `totalSpent.currency` is always the source asset
- `totalReceived.currency` is always the destination asset
- `residualTolerance` is a decimal ratio (e.g., "0.0123" = 1.23% tolerance remaining)
- `feeBreakdown` aggregates all fees across all legs
- `legs` may be an empty array for direct conversions or when legs are not materialized
- The order of `legs` represents the path sequence: first leg converts first asset to intermediate, last leg converts to final asset

**Version History**:
- 1.0.0: Initial structure

---

### PathLeg

**Class**: `SomeWork\P2PPathFinder\Application\Result\PathLeg`

**Purpose**: Describes a single conversion leg in a path.

**JSON Structure**:

```json
{
  "from": "USD",
  "to": "GBP",
  "spent": {
    "currency": "USD",
    "amount": "100.00",
    "scale": 2
  },
  "received": {
    "currency": "GBP",
    "amount": "79.60",
    "scale": 2
  },
  "fees": {
    "GBP": {
      "currency": "GBP",
      "amount": "0.40",
      "scale": 2
    }
  }
}
```

**Fields**:

| Field      | Type                  | Required | Description                              |
|------------|-----------------------|----------|------------------------------------------|
| `from`     | string                | Yes      | Source asset symbol (uppercase)          |
| `to`       | string                | Yes      | Destination asset symbol (uppercase)     |
| `spent`    | [Money](#money)       | Yes      | Amount spent in source asset             |
| `received` | [Money](#money)       | Yes      | Amount received in destination asset     |
| `fees`     | [MoneyMap](#moneymap) | Yes      | Fees for this leg (may be empty)         |

**Constraints**:
- `spent.currency` MUST equal `from`
- `received.currency` MUST equal `to`
- `fees` keys MUST be either `from` or `to` (or both)
- Asset symbols are always uppercase

**Notes**:
- The `received` amount is after fees have been deducted
- `fees` may contain entries for the source asset, destination asset, or both
- For multi-currency fee scenarios, both currencies may appear in `fees`

**Version History**:
- 1.0.0: Initial structure

---

### PathLegCollection

**Class**: `SomeWork\P2PPathFinder\Application\Result\PathLegCollection`

**Purpose**: Ordered collection of path legs.

**JSON Structure**:

An array of [PathLeg](#pathleg) objects:

```json
[
  {
    "from": "USD",
    "to": "GBP",
    "spent": { "currency": "USD", "amount": "100.00", "scale": 2 },
    "received": { "currency": "GBP", "amount": "80.00", "scale": 2 },
    "fees": {}
  },
  {
    "from": "GBP",
    "to": "EUR",
    "spent": { "currency": "GBP", "amount": "80.00", "scale": 2 },
    "received": { "currency": "EUR", "amount": "93.60", "scale": 2 },
    "fees": {}
  }
]
```

**Structure**:
- Array of PathLeg objects in order
- Empty collection is represented as `[]`
- Legs are ordered by path sequence

**Notes**:
- The first leg's `from` is the path's source asset
- The last leg's `to` is the path's destination asset
- Each leg's `to` should match the next leg's `from` (if any)

---

## Search Results

### SearchOutcome

**Class**: `SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchOutcome`

**Purpose**: Container for search results and guard metrics.

**JSON Structure**:

```json
{
  "paths": [
    {
      "totalSpent": {
        "currency": "USD",
        "amount": "100.00",
        "scale": 2
      },
      "totalReceived": {
        "currency": "EUR",
        "amount": "92.50",
        "scale": 2
      },
      "residualTolerance": "0.0000000000",
      "feeBreakdown": {},
      "legs": []
    }
  ],
  "guards": {
    "limits": {
      "expansions": 10000,
      "visited_states": 5000,
      "time_budget_ms": null
    },
    "metrics": {
      "expansions": 342,
      "visited_states": 156,
      "elapsed_ms": 12.456
    },
    "breached": {
      "expansions": false,
      "visited_states": false,
      "time_budget": false,
      "any": false
    }
  }
}
```

**Fields**:

| Field    | Type                                    | Required | Description                          |
|----------|-----------------------------------------|----------|--------------------------------------|
| `paths`  | array<[PathResult](#pathresult)>        | Yes      | Found paths (may be empty)           |
| `guards` | [SearchGuardReport](#searchguardreport) | Yes      | Search guard metrics                 |

**Notes**:
- `paths` is always present, even if empty (represented as `[]`)
- `paths` are ordered by the configured `PathOrderStrategy` (default: cost, then hops, then route signature)
- The number of paths may be limited by `PathSearchConfig.resultLimit`
- `guards` provides diagnostic information about search performance and limits

**Version History**:
- 1.0.0: Initial structure

---

### SearchGuardReport

**Class**: `SomeWork\P2PPathFinder\Application\PathFinder\Result\SearchGuardReport`

**Purpose**: Immutable snapshot describing how the search interacted with its guard rails.

**JSON Structure**:

```json
{
  "limits": {
    "expansions": 10000,
    "visited_states": 5000,
    "time_budget_ms": 1000
  },
  "metrics": {
    "expansions": 342,
    "visited_states": 156,
    "elapsed_ms": 12.456
  },
  "breached": {
    "expansions": false,
    "visited_states": false,
    "time_budget": false,
    "any": false
  }
}
```

**Fields**:

| Field     | Type   | Required | Description                              |
|-----------|--------|----------|------------------------------------------|
| `limits`  | object | Yes      | Configured search limits                 |
| `metrics` | object | Yes      | Actual search metrics                    |
| `breached`| object | Yes      | Boolean flags for limit breaches         |

#### `limits` Object

| Field            | Type     | Required | Description                              |
|------------------|----------|----------|------------------------------------------|
| `expansions`     | int      | Yes      | Maximum allowed node expansions          |
| `visited_states` | int      | Yes      | Maximum allowed visited states           |
| `time_budget_ms` | int|null | Yes      | Time budget in milliseconds (null = unlimited) |

#### `metrics` Object

| Field            | Type  | Required | Description                              |
|------------------|-------|----------|------------------------------------------|
| `expansions`     | int   | Yes      | Actual number of node expansions         |
| `visited_states` | int   | Yes      | Actual number of visited states          |
| `elapsed_ms`     | float | Yes      | Actual elapsed time in milliseconds      |

#### `breached` Object

| Field            | Type | Required | Description                                   |
|------------------|------|----------|-----------------------------------------------|
| `expansions`     | bool | Yes      | True if expansion limit was reached           |
| `visited_states` | bool | Yes      | True if visited states limit was reached      |
| `time_budget`    | bool | Yes      | True if time budget was exceeded              |
| `any`            | bool | Yes      | True if any limit was breached                |

**Notes**:
- All metric values start at 0 for a fresh search
- `elapsed_ms` is measured using high-resolution timers
- `breached.any` is a convenience field: true if any individual breach flag is true
- If `time_budget_ms` is `null`, `breached.time_budget` is always `false`
- Limits are enforced during the search; if breached, the search terminates early

**Version History**:
- 1.0.0: Initial structure

---

### PathResultSet

**Class**: `SomeWork\P2PPathFinder\Application\PathFinder\Result\PathResultSet<TPath>`

**Purpose**: Generic ordered collection of path results.

**JSON Structure**:

An array of path objects (typically [PathResult](#pathresult)):

```json
[
  {
    "totalSpent": {
      "currency": "USD",
      "amount": "100.00",
      "scale": 2
    },
    "totalReceived": {
      "currency": "EUR",
      "amount": "92.50",
      "scale": 2
    },
    "residualTolerance": "0.0000000000",
    "feeBreakdown": {},
    "legs": []
  },
  {
    "totalSpent": {
      "currency": "USD",
      "amount": "100.00",
      "scale": 2
    },
    "totalReceived": {
      "currency": "EUR",
      "amount": "91.80",
      "scale": 2
    },
    "residualTolerance": "0.0123456789",
    "feeBreakdown": {
      "EUR": {
        "currency": "EUR",
        "amount": "0.70",
        "scale": 2
      }
    },
    "legs": []
  }
]
```

**Structure**:
- Array of path objects (type depends on generic parameter `TPath`)
- Empty collection is represented as `[]`
- Paths are ordered according to the configured `PathOrderStrategy`

**Notes**:
- This is a generic type; the actual content type varies by usage
- When used with `PathFinderService`, `TPath` is typically `PathResult`
- The ordering is stable and deterministic given the same inputs and strategy

---

## Example: Complete Search Response

Here's a complete example showing a typical `SearchOutcome` with paths and guard metrics:

```json
{
  "paths": [
    {
      "totalSpent": {
        "currency": "USD",
        "amount": "100.00",
        "scale": 2
      },
      "totalReceived": {
        "currency": "EUR",
        "amount": "93.12",
        "scale": 2
      },
      "residualTolerance": "0.0000000000",
      "feeBreakdown": {
        "JPY": {
          "currency": "JPY",
          "amount": "750.00",
          "scale": 2
        },
        "EUR": {
          "currency": "EUR",
          "amount": "0.47",
          "scale": 2
        }
      },
      "legs": [
        {
          "from": "USD",
          "to": "JPY",
          "spent": {
            "currency": "USD",
            "amount": "100.00",
            "scale": 2
          },
          "received": {
            "currency": "JPY",
            "amount": "14250.00",
            "scale": 2
          },
          "fees": {
            "JPY": {
              "currency": "JPY",
              "amount": "750.00",
              "scale": 2
            }
          }
        },
        {
          "from": "JPY",
          "to": "EUR",
          "spent": {
            "currency": "JPY",
            "amount": "14250.00",
            "scale": 2
          },
          "received": {
            "currency": "EUR",
            "amount": "93.59",
            "scale": 2
          },
          "fees": {
            "EUR": {
              "currency": "EUR",
              "amount": "0.47",
              "scale": 2
            }
          }
        }
      ]
    },
    {
      "totalSpent": {
        "currency": "USD",
        "amount": "100.00",
        "scale": 2
      },
      "totalReceived": {
        "currency": "EUR",
        "amount": "91.54",
        "scale": 2
      },
      "residualTolerance": "0.0000000000",
      "feeBreakdown": {
        "EUR": {
          "currency": "EUR",
          "amount": "0.46",
          "scale": 2
        }
      },
      "legs": [
        {
          "from": "USD",
          "to": "EUR",
          "spent": {
            "currency": "USD",
            "amount": "100.00",
            "scale": 2
          },
          "received": {
            "currency": "EUR",
            "amount": "92.00",
            "scale": 2
          },
          "fees": {
            "EUR": {
              "currency": "EUR",
              "amount": "0.46",
              "scale": 2
            }
          }
        }
      ]
    }
  ],
  "guards": {
    "limits": {
      "expansions": 10000,
      "visited_states": 5000,
      "time_budget_ms": null
    },
    "metrics": {
      "expansions": 127,
      "visited_states": 43,
      "elapsed_ms": 8.234
    },
    "breached": {
      "expansions": false,
      "visited_states": false,
      "time_budget": false,
      "any": false
    }
  }
}
```

This example shows:
- Two paths found: one 2-hop path (USD -> JPY -> EUR) and one direct path (USD -> EUR)
- Fees in multiple currencies (JPY and EUR)
- Complete leg-by-leg breakdown for each path
- Guard metrics showing the search completed well within limits

---

## Usage in Production

### Parsing JSON Responses

All types can be serialized to JSON using `json_encode()`:

```php
$outcome = $service->findBestPaths($request);
$json = json_encode($outcome, JSON_PRETTY_PRINT);
```

### Type Safety

The PHPDoc annotations on `jsonSerialize()` methods provide full type information for static analysis tools like PHPStan and Psalm. These types are enforced at the type-checking level and documented here for API consumers.

### Backwards Compatibility

This JSON contract is considered part of the public API. Changes follow semantic versioning:
- **Patch releases** (1.0.x): Bug fixes only, no schema changes
- **Minor releases** (1.x.0): New optional fields may be added
- **Major releases** (x.0.0): Breaking schema changes allowed

---

## Related Documentation

- [API Stability Guide](api-stability.md) - Public API surface definitions
- [README.md](../README.md) - Usage examples and getting started
- [Decimal Strategy](decimal-strategy.md) - Precision handling for monetary values

---

**Document Version**: 1.0.0  
**Last Updated**: November 2024

