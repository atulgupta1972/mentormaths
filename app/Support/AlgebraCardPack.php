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
                    ? route('admin.algebra-cards.figure', ['pack' => $key, 'figure' => $filename]).'?v='.filemtime($path)
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
                'questions_relative' => 'samples/fraction-simplification-cards/questions.json',
                'guide_relative' => 'samples/fraction-simplification-cards/TEACHER_GUIDE.md',
            ],
        ];
    }

    /**
     * Student-style playable cards (MCQ + figure) for admin try mode.
     *
     * @return list<array{
     *     id: int,
     *     title: string,
     *     question: string,
     *     check_lines: list<string>,
     *     options: list<string>,
     *     correct_index: int,
     *     explanation: string,
     *     method_hint: string,
     *     diagram_url: string|null
     * }>
     */
    public static function playableCards(string $key): array
    {
        $pack = self::find($key);
        if ($pack === null) {
            return [];
        }

        $jsonPath = base_path((string) ($pack['questions_relative'] ?? ''));
        if (! is_file($jsonPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($jsonPath), true);
        $items = is_array($decoded['questions'] ?? null) ? $decoded['questions'] : [];
        $cards = [];

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = $index + 1;
            $filename = strtolower(basename((string) ($item['diagram_file'] ?? "q{$id}.png")));
            $options = array_values(array_filter(array_map(
                fn ($option) => trim((string) $option),
                is_array($item['options'] ?? null) ? $item['options'] : [],
            ), fn ($option) => $option !== ''));
            $checkLines = array_values(array_filter(array_map(
                fn ($line) => trim((string) $line),
                is_array($item['check_lines'] ?? null) ? $item['check_lines'] : [],
            ), fn ($line) => $line !== ''));

            $cards[] = [
                'id' => $id,
                'title' => self::cardTitle($key, $id),
                'question' => trim((string) ($item['question'] ?? '')),
                'check_lines' => $checkLines,
                'options' => $options,
                'correct_index' => (int) ($item['correct_index'] ?? 0),
                'explanation' => trim((string) ($item['explanation'] ?? '')),
                'method_hint' => trim((string) ($item['method_hint'] ?? '')),
                'diagram_url' => ($figPath = self::figurePath($key, $filename))
                    ? route('admin.algebra-cards.figure', ['pack' => $key, 'figure' => $filename]).'?v='.filemtime($figPath)
                    : null,
            ];
        }

        return $cards;
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
