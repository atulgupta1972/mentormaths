<?php

namespace App\Support;

/**
 * Built-in zip packs shown in the question-bank Upload pack dropdown.
 */
class SampleQuestionPack
{
    public const FRACTION_SIMPLIFICATION_CARDS = 'fraction_simplification_cards';

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     default_output_type: string,
     *     question_count: int,
     *     relative_path: string
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

    public static function absolutePath(string $key): ?string
    {
        $pack = self::find($key);
        if ($pack === null) {
            return null;
        }

        $path = base_path($pack['relative_path']);

        return is_file($path) ? $path : null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::options(), 'key');
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     default_output_type: string,
     *     question_count: int,
     *     relative_path: string
     * }>
     */
    private static function catalog(): array
    {
        return [
            [
                'key' => self::FRACTION_SIMPLIFICATION_CARDS,
                'label' => 'Fraction simplification cards (Class 6+)',
                'description' => '10 MCQ teaching cards — cancel trap in sums/differences (wrong vs right steps).',
                'default_output_type' => 'mcq',
                'question_count' => 10,
                'relative_path' => 'samples/fraction-simplification-cards/fraction-simplification-cards.zip',
            ],
        ];
    }

    /**
     * @param  array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     default_output_type: string,
     *     question_count: int,
     *     relative_path: string
     * }  $pack
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     default_output_type: string,
     *     question_count: int,
     *     relative_path: string
     * }|null
     */
    private static function enrich(array $pack): ?array
    {
        if (! is_file(base_path($pack['relative_path']))) {
            return null;
        }

        return $pack;
    }
}
