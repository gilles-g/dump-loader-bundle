# Custom steps

| Interface | Behaviour |
|---|---|
| `DumpLoader\Step\Step` | Always runs |
| `DumpLoader\Step\OptionalStep` | Checkbox in the UI, its name is the option key |

Autoconfigured. `rank` places the step (built-in: 50, 100, 200, 300), `weight` is its share of the
overall progress.

```php
namespace App\DumpLoader;

use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Percentage;
use DumpLoader\Step\LoadContext;
use DumpLoader\Step\OptionalStep;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepTracker;

final readonly class AnonymizeCustomers implements OptionalStep
{
    public function __construct(private Connection $connection)
    {
    }

    public function definition(): StepDefinition
    {
        return new StepDefinition(
            name: 'anonymize_customers',
            label: 'step.anonymize_customers.label',
            description: 'step.anonymize_customers.description',
            rank: 150,
            weight: 2,
        );
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function run(LoadContext $context, StepTracker $tracker): void
    {
        $tracker->log(LogTag::Run, 'Anonymizing customers.');
        $this->connection->executeStatement('UPDATE customer SET email = CONCAT("user", id, "@example.com")');
        $tracker->progress(Percentage::complete());
    }
}
```

- Talk to the `StepTracker`: log, progress, `abortRequested()`.
- Throw from `run()` to fail the job with that message.
- Label and description use the `DumpLoaderBundle` translation domain.

Real anonymization: [`gilles-g/db-anonymizer`](https://github.com/gilles-g/db-anonymizer), YAML
rules, plugged in at rank 250, after migrations.

## From YAML

A library ships a `DumpLoader\Step\StepFactory`. Its `configurationKey()` names an entry under
`steps`, `create()` turns that entry into a `Step`.

```yaml
dump_loader:
    steps:
        anonymization:
            seed: '%env(ANONYMIZER_SEED)%'
            tables:
                users:
                    columns:
                        email: { mask: email }
```

An unknown key fails the container build and lists the known ones.
