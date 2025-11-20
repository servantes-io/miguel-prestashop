<?php

use PrestaShop\CodingStandards\CsFixer\Config;

class MiguelConfig extends Config
{
    public function __construct($name = 'default')
    {
        parent::__construct($name);

        $this->setUsingCache(true);

        $finder = (PhpCsFixer\Finder::create())
            ->in(__DIR__)
            ->exclude([
                'vendor',
                'vendor2',
                'run',
                'tests',
            ]);
        $this->setFinder($finder);
    }

    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'blank_line_after_opening_tag' => false,
        ]);
    }
}

return new MiguelConfig();
