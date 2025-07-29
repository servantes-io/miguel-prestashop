<?php
$finder = (PhpCsFixer\Finder::create())
    ->in(__DIR__)
    ->exclude([
        'vendor',
        'vendor2',
        'run',
        'tests',
    ]);

$config = new PrestaShop\CodingStandards\CsFixer\Config();
return $config
    ->setUsingCache(true)
    ->setFinder($finder)
    ->setRules(array_merge($config->getRules(), [
        'blank_line_after_opening_tag' => false,
    ]))
;
