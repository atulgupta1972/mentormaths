<?php

namespace App\Support;

/**
 * Algebra teaching-card packs (Question bank → Algebra card).
 * Extensible — add more packs here as they are built.
 */
class AlgebraCardPack
{
    public const FRACTION_SIMPLIFICATION = 'fraction_simplification';

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     short_label: string,
     *     description: string,
     *     level: string,
     *     card_count: int,
     *     sample_pack_key: string|null,
     *     diagrams_relative: string,
     *     guide_relative: string|null
     * }>
     */
    public static function options(): array
    {
        return array_values(array_filter(
            array_map(fn (array $pack) => self::enrich($pack), self::catalog()),
            fn (?array $pack) => $pack !== null,
        ));
    }

    public static function find(string $key): ?array
    {
        foreach (self::options() as $pack) {
            if ($pack['key'] === $key) {
                return $pack;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, title: string, diagram_url: string|null}>
     */
    public static function cardsFor(string $key): array
    {
        $pack = self::find($key);
        if ($pack === null) {
            return [];
        }

        $dir = base_path($pack['diagrams_relative']);
        $cards = [];

        for ($i = 1; $i <= (int) $pack['card_count']; $i++) {
            $filename = "q{$i}.png";
            $path = $dir.DIRECTORY_SEPARATOR.$filename;
            $cards[] = [
                'id' => $i,
                'title' => self::cardTitle($key, $i),
                'diagram_url' => is_file($path)
                    ? route('admin.algebra-cards.figure', ['pack' => $key, 'figure' => $filename])
                    : null,
            ];
        }

        return $cards;
    }

    public static function figurePath(string $packKey, string $figure): ?string
    {
        $pack = self::find($packKey);
        if ($pack === null) {
            return null;
        }

        $basename = strtolower(basename($figure));
        if (! preg_match('/^q([1-9]|10)\.png$/', $basename)) {
            return null;
        }

        $path = base_path($pack['diagrams_relative'].'/'.$basename);

        return is_file($path) ? $path : null;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     short_label: string,
     *     description: string,
     *     level: string,
     *     card_count: int,
     *     sample_pack_key: string|null,
     *     diagrams_relative: string,
     *     guide_relative: string|null
     * }>
     */
    private static function catalog(): array
    {
        return [
            [
                'key' => self::FRACTION_SIMPLIFICATION,
                'label' => 'Fraction simplification cards',
                'short_label' => 'Cancel traps',
                'description' => 'Wrong vs right cancel in sums/differences — Class 6 onwards. Golden rule: cancel only a common factor of the whole numerator.',
                'level' => 'Class 6+',
                'card_count' => 10,
                'sample_pack_key' => SampleQuestionPack::FRACTION_SIMPLIFICATION_CARDS,
                'diagrams_relative' => 'samples/fraction-simplification-cards/diagrams',
                'guide_relative' => 'samples/fraction-simplification-cards/TEACHER_GUIDE.md',
            ],
        ];
    }

    /**
     * @param  array{
     *     key: string,
     *     label: string,
     *     short_label: string,
     *     description: string,
     *     level: string,
     *     card_count: int,
     *     sample_pack_key: string|null,
     *     diagrams_relative: string,
     *     guide_relative: string|null
     * }  $pack
     * @return array{
     *     key: string,
     *     label: string,
     *     short_label: string,
     *     description: string,
     *     level: string,
     *     card_count: int,
     *     sample_pack_key: string|null,
     *     diagrams_relative: string,
     *     guide_relative: string|null
     * }|null
     */
    private static function enrich(array $pack): ?array
    {
        if (! is_dir(base_path($pack['diagrams_relative']))) {
            return null;
        }

        return $pack;
    }

    private static function cardTitle(string $packKey, int $id): string
    {
        if ($packKey !== self::FRACTION_SIMPLIFICATION) {
            return "Card {$id}";
        }

        return match ($id) {
            1 => 'Cancel trap — numbers in a sum',
            2 => 'Matching addend trap',
            3 => 'Subtraction — same rule',
            4 => 'Denominator divides every term',
            5 => 'Three terms',
            6 => 'When cancel IS allowed',
            7 => 'Algebra — split the fraction',
            8 => 'Never cancel a variable in a sum',
            9 => 'Algebra subtraction + factor',
            10 => 'Master card — factor vs illegal cancel',
            default => "Card {$id}",
        };
    }
}
