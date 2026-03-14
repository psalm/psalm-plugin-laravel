# About plugin

The plugin helps Psalm to understand Laravel's code (which uses a lot of magic) better.
There are 2 main ways how it does it:
 - **easy**: by providing stub files (you can find them in `/stubs` dir)
 - **medium+**: using custom Handlers (see `/src/Handlers` dir)

## How it works

A single callstack looks like:

```
Plugin::__invoke
    Providers\ApplicationProvider::bootApp
        {instantiate Laravel Application}
    Plugin::buildSchema
        {always runs; parses migration files to build schema info}
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

## Documentation

- [Configuration](config.md) — plugin XML config options and environment variables
- [Architecture Decisions](contribute/decisions.md) — key design decisions and rationale
- [Debugging with Xdebug](contribute/xdebug.md) — how to debug plugin code

## External resources

- [Authoring Psalm Plugins](https://psalm.dev/docs/running_psalm/plugins/authoring_plugins/)
