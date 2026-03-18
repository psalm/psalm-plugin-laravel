# About plugin

## How it works

A single callstack looks like:

```
Plugin::__invoke
    Providers\ApplicationProvider::bootApp
        {instantiate Laravel Application}
    Plugin::buildSchema (only when columnFallback="migrations")
        {parse migration files to build schema info}
    Plugin::generateAliasStubs
        {read AliasLoader::getInstance()->getAliases() and write aliases.stubphp}
    Plugin::registerHandlers
        - Container
        - Eloquent (incl. ModelRegistrationHandler for AfterCodebasePopulated)
        - Helpers (that not covered by stubs)
    Plugin::registerStubs
        - common
        - for specific laravel version
        - taint analysis
        - aliases.stubphp (generated)

--- later, after Psalm scans all project files ---

ModelRegistrationHandler::afterCodebasePopulated
    {discover Model subclasses from Psalm's codebase, register property handlers}
```

## External resources

- [Authoring Psalm Plugins](https://psalm.dev/docs/running_psalm/plugins/authoring_plugins/)
