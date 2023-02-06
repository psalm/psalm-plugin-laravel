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
    Plugin::generateStubFiles
        Providers\FacadeStubProvider::generateStubFile
            {call `ide-helper:generate` command} // generates "facades.stubphp"
        Providers\ModelStubProvider::generateStubFile
            Fakes\FakeModelsCommand::run(schema_aggregator(migrations))
                - override parent ModelsCommand::getPropertiesFromTable (extract info from migration files instead using DB connection)
                - {call `ide-helper:models --nowrite --reset`} // generates "models.stubphp"
    Plugin::registerHandlers
        - Container
        - Eloquent
        - Helpers (that not covered by stubs)
    Plugin::registerStubs
        - common
        - for speficic laravel version
        - facades.stubphp (generated)
        - models.stubphp (generated)
```

## Materials

 - [Authoring Psalm Plugins](https://psalm.dev/docs/running_psalm/plugins/authoring_plugins/)
