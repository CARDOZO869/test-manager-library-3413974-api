<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

class ArchitectureTest extends TestCase
{
    private function getFiles(string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) return [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    private function checkImports(string $dir, array $forbiddenNamespaces, string $layerName)
    {
        $files = $this->getFiles($dir);
        foreach ($files as $file) {
            $content = file_get_contents($file);
            preg_match_all('/^use\s+([^;]+);/m', $content, $matches);
            foreach ($matches[1] as $import) {
                foreach ($forbiddenNamespaces as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $import, "Architecture violation in {$layerName}: {$file} imports {$import}");
                }
            }
        }
    }

    public function test_domain_does_not_depend_on_application_or_infrastructure()
    {
        $this->checkImports(__DIR__ . '/../../app/Domain', ['App\Application', 'App\Infrastructure', 'App\Http', 'App\Console', 'App\Providers', 'Illuminate\\'], 'Domain');
        $this->assertTrue(true);
    }

    public function test_application_does_not_depend_on_infrastructure()
    {
        $this->checkImports(__DIR__ . '/../../app/Application', ['App\Infrastructure', 'App\Http', 'App\Console', 'App\Providers', 'Illuminate\\', 'Symfony\\'], 'Application');
        $this->assertTrue(true);
    }
    public function test_infrastructure_is_instantiated_only_in_composition(): void
    {
        foreach ($this->getFiles(__DIR__.'/../../app') as $file) {
            if (str_contains(str_replace('\\','/',$file),'/Providers/')) continue;
            $content=file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/new\s+\\\\?App\\\\Infrastructure\\\\/', $content, $file);
            preg_match_all('/use\s+App\\\\Infrastructure\\\\[^;]+\\\\(\w+);/', $content, $matches);
            foreach($matches[1] as $name) self::assertDoesNotMatchRegularExpression('/new\s+'.preg_quote($name,'/').'\s*\(/',$content,$file);
        }
    }
}
