<?php

declare(strict_types=1);

namespace CodeMap\Tests;

use CodeMap\Query\FileKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileKindTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function paths(): iterable
    {
        yield ['apps/customer/app/Comparison/ui/TariffTile/TariffTile.tsx', FileKind::SRC];
        yield ['apps/customer/app/Comparison/ui/TariffTile/TariffTile.css.ts', FileKind::SRC];
        yield ['apps/customer/app/Shared/pageUnloadState.test.ts', FileKind::TEST];
        yield ['apps/web/Foo.spec.tsx', FileKind::TEST];
        yield ['src/__tests__/foo.ts', FileKind::TEST];
        yield ['e2e/checkout.ts', FileKind::TEST];
        yield ['tests/Unit/OrderTest.php', FileKind::TEST];
        yield ['src/Service/LatestTestimonials.php', FileKind::SRC];
        yield ['apps/customer/app/Comparison/ui/TariffTile/TariffTile.stories.tsx', FileKind::STORY];
        yield ['.storybook/preview.tsx', FileKind::STORY];
        yield ['apps/customer/app/Checkout/shared/storybook/mockTariffOffer.ts', FileKind::STORY];
        yield ['packages/shared/generated/api/models/TariffScope.ts', FileKind::GENERATED];
        yield ['packages/shared/generated/api/models/TariffScope.test.ts', FileKind::GENERATED];
        yield ['src/schema.generated.ts', FileKind::GENERATED];
    }

    #[DataProvider('paths')]
    public function testClassifiesPaths(string $path, string $kind): void
    {
        self::assertSame($kind, FileKind::of($path));
    }

    public function testParseExcludeAcceptsAliases(): void
    {
        self::assertSame([], FileKind::parseExclude(null));
        self::assertSame([], FileKind::parseExclude(' '));
        self::assertEqualsCanonicalizing([FileKind::TEST, FileKind::STORY, FileKind::GENERATED], FileKind::parseExclude('tests, stories,generated'));
        self::assertSame([FileKind::TEST], FileKind::parseExclude('spec,test'));
    }

    public function testParseExcludeRejectsUnknownValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FileKind::parseExclude('tests,docs');
    }
}
