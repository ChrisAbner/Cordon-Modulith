<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use Cordon\Module\ModuleMap;

/**
 * Everything a rule needs. Rules must treat it as read-only.
 *
 * @api
 */
final readonly class AnalysisContext
{
    /** The policy, aware of these modules: it needs them to tell what a nested module exposes. */
    public PublicApiPolicy $policy;

    /**
     * @param  list<Edge>  $edges  Cross-module references only.
     * @param  array<string, string>  $parseErrors  Files that could not be parsed (file => message): their references are missing from $edges.
     * @param  list<Edge>  $hostEdges  References from the code outside the modules (host.paths) to a module, from Module::host().
     * @param  list<Reference>  $signatures  References in the public signatures of module classes (Reference::$signature set), within a module too.
     * @param  list<Reference>  $externalReferences  References in module files to a class that no module owns (a PHP class, a package's, the project's own), for external_dependency.
     * @param  TableMap  $tables  Who owns each table and who touches the tables of another module, for the tables rule; empty unless that rule is on.
     */
    public function __construct(
        public ModuleMap $modules,
        public SymbolTable $symbols,
        PublicApiPolicy $policy,
        public array $edges,
        public array $parseErrors = [],
        public array $hostEdges = [],
        public array $signatures = [],
        public array $externalReferences = [],
        public TableMap $tables = new TableMap,
    ) {
        $this->policy = $policy->withModules($modules);
    }
}
