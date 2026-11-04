# Comparison in practice: parsing, reflection, or both?

I maintain Cordon Modulith. People often ask how it compares to Pest's `arch()` and to deptrac. Instead of comparing feature lists, I ran the three tools on the same small fixture and looked at what each one sees. This is not a benchmark, and it is not "which one wins". Each tool makes a different trade-off, and those trade-offs decide where each one fits.

## The question each tool answers

- **Pest `arch()`** answers "does this namespace use that namespace?" inside your test suite.
- **deptrac** answers "does this layer depend on a layer it is not allowed to?" for any PHP code, based on a layer map you write.
- **Cordon** answers "does this module reach into another module's internals, depend on a module it did not declare, or take part in a dependency cycle?". It finds the modules from nwidart, InterNACHI or `app/Modules` by itself.

How each tool reads your code matters as much as which question it answers.

## How they read code

All three use nikic/php-parser, so all three parse. The differences are in what happens around the parsing.

- **Cordon only parses.** It never loads or runs the analysed code (ADR 0001). A class that cannot be autoloaded is analysed like any other.
- **Pest `arch()` parses and then requires reflection.** Its engine (`ta-tikoma/phpunit-architecture-test` 0.8.7) builds a `ReflectionClass` for each file. If that fails, the file is dropped from the analysis without a warning. It also keeps only the referenced names that `class_exists()` or `interface_exists()` accept. To build the reflection, it has to autoload the class.
- **deptrac parses, including docblocks.** It does not need the code to load, and it also reads types written in docblocks.

## The experiment

The fixture has three modules: Billing, Catalog and Shared. It is the one Cordon uses in its own tests ([`tests/Fixtures/namespace-app`](https://github.com/ChrisAbner/Cordon-Modulith/tree/main/tests/Fixtures/namespace-app)), plus three files that probe the edges. I wrote the closest version of the same rules in each tool:

- For Pest, three `arch()` expectations, listing by hand which Catalog namespaces are internal.
- For deptrac, a 49-line YAML that splits each module into a public layer and an internal one. It uses deptrac's `attribute` collector to honour `#[Internal]` and `#[PublicApi]`.

The setup was PHP 8.4, Laravel 13.40, Cordon 0.1.1, Pest 4.7.8 and deptrac 4.7.2.

| Case | Cordon | Pest `arch()` | deptrac |
|---|---|---|---|
| Billing uses Catalog's internal `Product` model | reported | reported | reported |
| Billing uses an interface in `Contracts` marked `#[Internal]` | reported | reported, because it was listed by hand | reported (`attribute` collector) |
| A class that cannot be autoloaded uses `Product` | reported | **passes silently** | reported |
| `Product` only appears in a `@param` docblock | missed | missed | reported |
| `Product` only appears as a string, `app('App\…\Product')` | missed | missed | missed |
| Billing and Catalog depend on each other | reported as a cycle | no cycle detection | no cycle detection |

Three results stood out to me.

**A test that passes is not always a test that checked.** Pest went green on a class that clearly uses an internal model, because that class could not be autoloaded. A missing parent class is enough. So is a file whose folder does not match its namespace: the module's `tests/` folder against the `Tests\` namespace hid a second case the same way. Cordon does not see this kind of gap because it never needs the class to load. I did not expect it to be silent.

**deptrac sees more than Cordon does.** It caught a dependency that exists only in a docblock, which Cordon deliberately ignores (more on that below). The [comparison page](./comparison) used to understate this, and it has been corrected. Deptrac *can* express a per-module public API, if you split layers and use the `attribute` collector. What Cordon saves you is writing and maintaining that map, not the capability itself.

**Nobody catches strings.** `app('App\Modules\Catalog\Models\Product')` got past all three tools. If your code resolves classes from strings, none of these checks will cover it.

## When to use each

- **Pest `arch()`** for conventions *inside* a module: "models extend `Model`", "controllers have no public properties", presets. It needs no setup and lives next to your tests. If you also use it for boundaries, make sure every analysed class autoloads in the test environment, or some rules will pass without checking anything.
- **deptrac** when you need layered rules (domain, application, infrastructure), when you are not on Laravel, or when docblock types carry real dependencies in your code base. Expect to write and maintain the layer map.
- **Cordon** for the edges *between* Laravel modules: public API by namespace and attributes, `depends_on`, cycles, and a baseline for an existing app, all without writing a layer map. It does not replace the other two for conventions or layers.

They combine well: deptrac or Pest inside each module, and Cordon between modules.

## The trade-off Cordon made, and what I'd do differently today

Cordon ignores docblock-only types and string class names on purpose. A dependency counts only when it is a real class name in code. The aim was zero false positives in CI, because a boundary check that cries wolf gets switched off. The cost shows in the table: deptrac caught a dependency that Cordon missed. In exchange, Cordon checks the whole app (1000 files and 20 modules in about 1 second in its CI benchmark) and never needs the analysed code to load.

What I'd do differently today:

- **Make docblock types an opt-in.** deptrac shows that this is practical. Teams that lean on generics in `@var` and `@param` should be able to turn it on.
- **Treat "analysed nothing" as a failure from day one.** The same lesson as Pest's silent pass applies to Cordon. Version 0.1.1 exited with 0 when it found no modules, and its GitHub output did not mention files it could not parse. Both are fixed in 0.2.0.

The configs, the extra fixture files and the raw output of every run are in [`docs/comparison-evidence`](https://github.com/ChrisAbner/Cordon-Modulith/tree/main/docs/comparison-evidence), so you can reproduce each row of the table.
