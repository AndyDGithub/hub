<?php

namespace Tests\Unit;

use App\Services\Knowledge\ErrorNormalizer;
use App\Services\Knowledge\MegadocParser;
use App\Services\Knowledge\SecretScanner;
use PHPUnit\Framework\TestCase;

class KnowledgeParsingTest extends TestCase
{
    public function test_same_error_in_different_files_has_same_fingerprint(): void
    {
        $n = new ErrorNormalizer;

        $this->assertSame(
            $n->fingerprint('File "/home/andy/a.py", line 12, in <module>: ModuleNotFoundError: No module named \'numpy\''),
            $n->fingerprint('File "/tmp/other/b.py", line 480, in <module>: ModuleNotFoundError: No module named \'numpy\''),
        );
        // Le nom du module est l'information utile : il doit changer l'empreinte.
        $this->assertNotSame(
            $n->fingerprint("No module named 'numpy'"),
            $n->fingerprint("No module named 'torch'"),
        );
    }

    public function test_skills_are_split_by_h3_and_code_comments_are_not_headings(): void
    {
        $md = <<<'MD'
        # Megadoc
        ## Table des matières
        1. [DevOps](#devops)
        ## 4. DevOps & CI/CD
        **Module : TSM-SoftwEng**
        ### Pre-commit Hooks
        Lance des contrôles avant chaque commit.
        ```yaml
        # .pre-commit-config.yaml
        repos: []
        ```
        ### Linter
        Analyse statique.
        MD;

        $fiches = (new MegadocParser)->parseSkills($md, 'hesso/megadoc_skills.md');

        $this->assertCount(2, $fiches);
        $this->assertSame('Pre-commit Hooks', $fiches[0]['title']);
        $this->assertStringContainsString('# .pre-commit-config.yaml', $fiches[0]['body']);
        $this->assertSame(['DevOps & CI/CD', 'TSM-SoftwEng'], $fiches[0]['tags']);
        $this->assertSame('Lance des contrôles avant chaque commit.', $fiches[0]['excerpt']);
        $this->assertSame('hesso/megadoc_skills.md#devops-cicd--pre-commit-hooks', $fiches[0]['source']);
    }

    public function test_page_type_depends_on_source_line(): void
    {
        $parser = new MegadocParser;

        $this->assertSame('project', $parser->parsePage("# API\n\n**Source :** `/x`", 'megadoc/a.md', 'a')['type']);
        $this->assertSame('note', $parser->parsePage("# Favoris\n\nliens", 'megadoc/b.md', 'b')['type']);
    }

    public function test_secret_scanner(): void
    {
        $s = new SecretScanner;

        $this->assertContains('clé OpenAI/Anthropic', $s->scan('OPENAI_API_KEY=sk-proj-abcdefghijklmnopqrstuvwxyz123456'));
        $this->assertContains('mot de passe en clair', $s->scan('DB_PASSWORD=hunter2hunter2'));
        $this->assertSame([], $s->scan("PASSWORD_PEPPER=\nAPI_KEY=<ta-clé>\nexport TOKEN=\${TOKEN}"));
    }
}
