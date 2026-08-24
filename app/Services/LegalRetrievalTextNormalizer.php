<?php

namespace App\Services;

use Normalizer;

class LegalRetrievalTextNormalizer
{
    public function normalize(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        $normalized = str_replace("\u{00A0}", ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return mb_strtolower(trim($normalized));
    }

    public function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u', $this->normalize($text), $matches);
        $minimum = (int) config('legal_analysis.retrieval.min_term_length', 4);
        $stopWords = array_fill_keys(array_map(
            fn (string $word) => $this->stem($this->normalize($word)),
            array_merge(...array_values(config('legal_analysis.stop_words', []))),
        ), true);

        return array_values(array_filter(array_map(
            fn (string $token) => $this->stem($token),
            $matches[0] ?? [],
        ), fn (string $token) => mb_strlen($token) >= $minimum && ! isset($stopWords[$token])));
    }

    public function stem(string $token): string
    {
        $token = $this->normalize($token);

        if (preg_match('/[әіңғүұқөһ]/u', $token) === 1) {
            $suffixes = [
                'ларының', 'лерінің', 'дардың', 'дердің', 'тардың', 'тердің',
                'ның', 'нің', 'дың', 'дің', 'тың', 'тің', 'лық', 'лік', 'дық', 'дік',
                'дар', 'дер', 'тар', 'тер', 'лар', 'лер', 'ға', 'ге', 'қа', 'ке',
                'да', 'де', 'та', 'те', 'ды', 'ді', 'ты', 'ті', 'ны', 'ні',
            ];
        } elseif (preg_match('/[а-яё]/u', $token) === 1) {
            $suffixes = [
                'иями', 'ями', 'ами', 'ского', 'скому', 'ческому', 'ческой',
                'ениями', 'ение', 'ения', 'ений', 'ировать', 'ировать',
                'ными', 'ного', 'ному', 'ный', 'ная', 'ное', 'ные', 'ных', 'ным',
                'ого', 'ему', 'ому', 'ими', 'ыми', 'иях', 'ах', 'ях',
                'ость', 'ости', 'ской', 'ский', 'ская', 'ское',
                'ться', 'ать', 'ять', 'ить', 'ны', 'на',
                'ов', 'ев', 'ей', 'ой', 'ий', 'ый', 'ая', 'яя', 'ое', 'ее',
                'ые', 'ие', 'ию', 'ью', 'ия', 'ам', 'ям', 'ом', 'ем',
                'ы', 'и', 'а', 'я', 'у', 'ю', 'е', 'о',
            ];
        } else {
            return $token;
        }

        foreach ($suffixes as $suffix) {
            if (mb_strlen($token) - mb_strlen($suffix) >= 4 && str_ends_with($token, $suffix)) {
                return mb_substr($token, 0, mb_strlen($token) - mb_strlen($suffix));
            }
        }

        return $token;
    }

    public function containsStemPhrase(string $text, string $phrase): bool
    {
        $textTokens = $this->tokens($text);
        $phraseTokens = $this->tokens($phrase);
        $length = count($phraseTokens);

        if ($length === 0 || $length > count($textTokens)) {
            return false;
        }

        for ($index = 0; $index <= count($textTokens) - $length; $index++) {
            if (array_slice($textTokens, $index, $length) === $phraseTokens) {
                return true;
            }
        }

        return false;
    }
}
