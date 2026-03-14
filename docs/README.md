# About plugin

The plugin helps Psalm to understand Laravel’s code (which uses a lot of magic) better.
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
        {parse migration files to build schema info}
    Providers\ModelDiscoveryProvider::discoverModels
        {discover model classes for property inference}
    Plugin::generateAliasStubs
        {read Illuminate\Foundation\AliasLoader::getInstance()->getAliases() and write aliases.stubphp}
    Plugin::registerHandlers
        - Container
        - Eloquent
        - Helpers (that not covered by stubs)
    Plugin::registerStubs
        - common
        - for specific laravel version
        - taint analysis
        - aliases.stubphp (generated)
```

## Materials

 - [Authoring Psalm Plugins](https://psalm.dev/docs/running_psalm/plugins/authoring_plugins/)
