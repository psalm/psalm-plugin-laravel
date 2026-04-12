# Application tests

Creates a minimal Laravel app and runs Psalm over its codebase. The baseline file can be updated by running `./laravel-test.sh -u`.

## Domain: Auto Repair Shop

The test app models a vehicle repair shop where **Customers** bring **Vehicles** to **Mechanics** for service. This single domain naturally covers all Laravel relationship types, custom builders, custom collections, scopes, accessors, and polymorphic patterns.

### Entities

- **Customer** — car owner (Authenticatable, SoftDeletes)
- **Vehicle** — car/truck belonging to a customer
- **Mechanic** — technician who performs repairs
- **WorkOrder** — a single repair visit linking vehicle, mechanic, and parts
- **Part** — spare part (brake pads, oil filter, etc.)
- **Supplier** — provides parts to the shop
- **Invoice** — billing document for a completed work order
- **DamageReport** — damage assessment (polymorphic: vehicle, work order)
- **MechanicSpecialization** — mechanic skill area (engine, transmission, electrical)

### Relationship map

```
Customer -- HasMany --> Vehicle
Customer -- HasOneOfMany --> Vehicle (latest acquired)
Customer -- HasManyThrough --> WorkOrder (through Vehicle)
Customer -- MorphedByMany --> Admin (bookmarks)

Vehicle -- BelongsTo --> Customer
Vehicle -- HasMany --> WorkOrder
Vehicle -- MorphOne --> DamageReport (latest)
Vehicle -- MorphOneOfMany --> DamageReport (most severe)
Vehicle -- MorphMany --> DamageReport (all)

Mechanic -- HasMany --> WorkOrder
Mechanic -- HasOneThrough --> Customer (through Vehicle)
Mechanic -- BelongsToMany --> MechanicSpecialization
Mechanic -- MorphedByMany --> Admin (bookmarks)

WorkOrder -- BelongsTo --> Vehicle
WorkOrder -- BelongsTo --> Mechanic
WorkOrder -- HasOne --> Invoice
WorkOrder -- BelongsToMany --> Part (pivot: quantity, unit_price)
WorkOrder -- MorphMany --> DamageReport

Invoice -- BelongsTo --> WorkOrder
Invoice -- MorphTo --> Customer | Supplier (billable)

Supplier -- HasMany --> Part
Supplier -- MorphedByMany --> Admin (bookmarks)

Admin -- MorphToMany --> Customer, Mechanic, Supplier, WorkOrder (bookmarkable)

Part -- BelongsTo --> Supplier
Part -- BelongsToMany --> WorkOrder
Part -- MorphTo --> Supplier | WorkOrder (orderedBy)

DamageReport -- MorphTo --> Vehicle | WorkOrder
MechanicSpecialization -- BelongsToMany --> Mechanic
```

### Relationship type coverage

| Type           | Example                                                    |
|----------------|------------------------------------------------------------|
| HasOne         | WorkOrder -> Invoice                                       |
| HasMany        | Customer -> Vehicles, Vehicle -> WorkOrders                |
| BelongsTo      | Vehicle -> Customer, WorkOrder -> Mechanic                 |
| BelongsToMany  | WorkOrder <-> Part, Mechanic <-> MechanicSpecialization    |
| HasOneThrough  | Mechanic -> Customer (through Vehicle)                     |
| HasManyThrough | Customer -> WorkOrders (through Vehicle)                   |
| HasOneOfMany   | Customer -> Vehicle (latest acquired)                      |
| MorphOne       | Vehicle -> DamageReport (latest)                           |
| MorphOneOfMany | Vehicle -> DamageReport (most severe)                      |
| MorphMany      | Vehicle -> DamageReports, WorkOrder -> DamageReports       |
| MorphTo        | DamageReport -> reportable                                 |
| MorphToMany    | Admin -> Customers/Mechanics/Suppliers/WorkOrders (bookmarkable) |
| MorphedByMany  | Customer/Mechanic/Supplier -> Admins (bookmarkable)        |

### Custom builders and collections

- **WorkOrderBuilder** — custom Eloquent builder (via `#[UseEloquentBuilder]`): `whereCompleted()`, `wherePending()`, `whereByMechanic(int $id)`
- **VehicleBuilder** — custom builder (via `newEloquentBuilder()` override): `whereElectric()`, `whereByMake(string $make)`
- **MechanicBuilder** — custom builder (via static `$builder` property): `whereCertified()`
- **WorkOrderCollection** — custom collection (via `#[CollectedBy]`): `totalLaborHours()`
- **PartCollection** — custom collection (via `newCollection()` override)
- **DamageReportCollection** — custom collection (via static `$collectionClass` property)

Three builder registration patterns and three collection registration patterns ensure the plugin handles all variants.

Additionally, **InvoiceBuilder** and **InvoiceCollection** extend their base classes with concrete model types (`extends Builder<Invoice>`, `extends Collection<int, Invoice>`) instead of declaring `@template` parameters. This tests that the plugin returns a plain `TNamedObject` instead of `TGenericObject`, avoiding `TooManyTemplateParams` errors.

### Scopes

- Legacy `scope*()` methods: `Vehicle::scopeByMake($query, string $make)`, `Mechanic::scopeExperienced($query)`
- Modern `#[Scope]` attribute: `WorkOrder::completed(Builder $query)`, `Vehicle::electric(Builder $query)`

### Accessors and mutators

On Customer (Authenticatable model):
- Legacy accessor: `getFirstNameUsingLegacyAccessorAttribute()`
- Legacy mutator: `setNicknameAttribute()`
- Modern `Attribute` accessor: `firstName` (get + set)
- Read-only `Attribute`: `displayName` (get only)

### Archetype models (non-domain)

These models test specific Psalm plugin features (PK types, traits) and are not part of the repair shop domain. Reuse before creating new ones:

| Model               | Purpose                                                               |
|---------------------|-----------------------------------------------------------------------|
| `Admin`             | Second Authenticatable (multi-guard) + bookmarks domain entities      |
| `UuidModel`         | `HasUuids` trait (string PK)                                          |
| `UlidModel`         | `HasUlids` trait (string PK)                                          |
| `CustomPkUuidModel` | `HasUuids` with custom `$primaryKey`                                  |
| `Secret`            | Extends abstract UUID model + custom collection via `newCollection()` |
| `Shop`              | Non-generic relationship resolution testing (#497)                    |

### Contributing

- **Reuse existing models** before creating new ones. Each model represents an archetype, not a single test case.
- All domain models should belong to the repair shop. Do not introduce unrelated domains.
- When adding a new relationship type or pattern, find the entity where it fits naturally in the domain.
- Non-domain archetype models (UUID, ULID, multi-guard) live alongside domain models but are documented separately above.
- Each pattern (builder registration, collection registration, scope style, accessor style) is assigned to a specific model — check the sections above before adding duplicates.
