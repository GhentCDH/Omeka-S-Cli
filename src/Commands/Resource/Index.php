<?php
namespace OSC\Commands\Resource;

use OSC\Resource\ResourceType;

// one set of commands per resource type (item:*, item-set:*); no variables here, since this file is
// required into the scope of src/Commands/Index.php
return array_merge(...array_map(
    fn(ResourceType $type) => [
        new ListCommand($type),
        new SearchCommand($type),
        new ExportCommand($type),
        new ImportCommand($type),
    ],
    [ResourceType::Item, ResourceType::ItemSet]
));
