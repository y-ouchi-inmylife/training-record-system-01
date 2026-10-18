<?php

namespace App\Support;

/**
 * 性格診断の設問の定義（設問の版 v1。2026-10 追加）。
 *
 * トレーナーさんの診断「愛犬パーソナリティ＆ウェルネス診断」（Google フォーム＋GAS）の設問・選択肢・カテゴリ・
 * タイプを、中身を変えずにそのまま移したもの（要件定義書 6-15-18・D-1200、db-schema.md 5-23〜5-25）。
 * 設問は DB の表にせずコードに持つ（画面から直す機能は持たない）。採点は PersonalityDiagnosisScorer。
 *
 * - 設問の文は、丸数字（カテゴリの中の番号）と、そのあとの空白の有無も今のフォームの文のまま
 * - Q11 の文末は、2026-10-18 にトレーナーさんに確認し「…喧嘩になり」から「…喧嘩になる」に直した（要件定義書 D-1200 の注記）。
 *   文末の誤りの直しで、設問の意味も採点も変わらないため、版（v1）は上げない
 * - 設問の意味や採点が変わるように文を直すときは、設問の版を上げ、前の版の設問も残す（昔の結果を答えたときの文で表示するため）
 * - 文を直すときは、要件定義書 D-1200 の設問一覧も同時に直すこと
 */
class PersonalityDiagnosisQuestions
{
    /** 今の設問の版（D-1200 question_version） */
    public const VERSION = 'v1';

    /** 「未経験」（その状況になったことがない）の回答の値。採点では平均から除く（db-schema.md 5-25.） */
    public const NOT_EXPERIENCED = 'na';

    /**
     * 7 カテゴリ（値 → 表示名）。この並びが画面に出す順で、タイプの判定で同点のときの優先の順（上が先）。
     * 値は D-1200 の score_{値} の列名にも使う（db-schema.md 5-23.）
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'people' => '対人',
            'dogs' => '対犬',
            'possessiveness' => '所有欲',
            'separation' => '分離不安',
            'fear' => '恐怖心',
            'trainability' => '訓練性能',
            'excitability' => '興奮性',
        ];
    }

    /**
     * 50 問（ID → カテゴリの値・設問の文）。Q01 から順に並べる
     *
     * @return array<string, array{category: string, text: string}>
     */
    public static function questions(): array
    {
        return [
            'Q01' => ['category' => 'people', 'text' => '①知らない人が家に入ってきた時に吠える・唸る'],
            'Q02' => ['category' => 'people', 'text' => '②窓の外を誰かが通りかかっただけで、敏感に反応して吠える'],
            'Q03' => ['category' => 'people', 'text' => '③散歩中、向こうから人が歩いてくるだけで警戒して吠える'],
            'Q04' => ['category' => 'people', 'text' => '④知らない人が撫でようと手を伸ばした時、サッと避けたり「ウーッ」と唸る'],
            'Q05' => ['category' => 'people', 'text' => '⑤知らない人が同じ部屋にいるだけで、逃げたり物陰に隠れたりする'],
            'Q06' => ['category' => 'people', 'text' => '⑥宅配便の人など、特定の制服や帽子をかぶった人に強く反応する'],
            'Q07' => ['category' => 'people', 'text' => '⑦外出先で、知らない子供が近くに来ると怖がったり吠えたりする'],
            'Q08' => ['category' => 'people', 'text' => '⑧飼い主が誰かと立ち話をしていると、相手に吠えたり間に割り込んだりする'],
            'Q09' => ['category' => 'dogs', 'text' => '① 遠くに犬を見つけただけで、グイグイ引っ張ったり吠えたりする'],
            'Q10' => ['category' => 'dogs', 'text' => '②すれ違う時、相手の犬に飛びかかろうとしたり唸ったりする'],
            'Q11' => ['category' => 'dogs', 'text' => '③ドッグランなどで他の犬に追いかけられると、本気で怒って喧嘩になる'],
            'Q12' => ['category' => 'dogs', 'text' => '④ 大きな犬が近づいてくると、ガタガタ震えたり飼い主の後ろに隠れる'],
            'Q13' => ['category' => 'dogs', 'text' => '⑤小さな犬やパピーに対して、しつこく追いかけ回して嫌がらせをする'],
            'Q14' => ['category' => 'dogs', 'text' => '⑥ 相手が吠えてくると、自分も負けじと激しく吠え返す'],
            'Q15' => ['category' => 'dogs', 'text' => '⑦ 他の犬が飼い主に甘えていると、その犬を追い払おうとする'],
            'Q16' => ['category' => 'dogs', 'text' => '⑧ 角から急に犬が現れたり、長いリードで勢いよく近づかれたりすると、パニックや攻撃的になる'],
            'Q17' => ['category' => 'possessiveness', 'text' => '① ご飯を食べている時、器に手を伸ばすと取られまいとして唸る'],
            'Q18' => ['category' => 'possessiveness', 'text' => '② おもちゃやガムを噛んでいる時、取り上げようとすると怒る'],
            'Q19' => ['category' => 'possessiveness', 'text' => '③ ソファやベッドで寝ている時、どかそうとすると抵抗して唸る'],
            'Q20' => ['category' => 'possessiveness', 'text' => '④ 足先や耳など、体が汚れて拭こうとした時に嫌がって口が出る'],
            'Q21' => ['category' => 'possessiveness', 'text' => '⑤ 自分の思い通りにならない時（おやつをくれない等）、唸って要求する'],
            'Q22' => ['category' => 'separation', 'text' => '① 飼い主が着替えたりカバンを持ったりすると、ソワソワして落ち着かなくなる'],
            'Q23' => ['category' => 'separation', 'text' => '② 留守番中、ずっと鳴き続けたり遠吠えをしたりしている（近所からの指摘など）'],
            'Q24' => ['category' => 'separation', 'text' => '③留守番中、自分の周りにあるもの（家具、ドア、ケージの柵、自分のベッド、トイレシーツなど）をかじったり壊したりする'],
            'Q25' => ['category' => 'separation', 'text' => '④普段はトイレができるのに、留守番の時だけわざと粗相をする'],
            'Q26' => ['category' => 'separation', 'text' => '⑤帰宅した時、息が切れるほど興奮して、なかなか落ち着かない'],
            'Q27' => ['category' => 'separation', 'text' => '⑥家にいても、飼い主がトイレや別室に行くだけでドアの前で鳴く'],
            'Q28' => ['category' => 'separation', 'text' => '⑦ 飼い主がいない間、自分の手足をずっと舐めたり噛んだりして傷つけている'],
            'Q29' => ['category' => 'fear', 'text' => '① 雷や花火の音がすると、パニックになって家中を逃げ回る'],
            'Q30' => ['category' => 'fear', 'text' => '② 掃除機やドライヤーの音を怖がって逃げる、または攻撃する'],
            'Q31' => ['category' => 'fear', 'text' => '③病院やサロンの入り口で、足を踏ん張って入るのを全力で拒否する'],
            'Q32' => ['category' => 'fear', 'text' => '④階段やツルツルした床、網状の溝（グレーチング）などを歩くのを極端に嫌がる'],
            'Q33' => ['category' => 'fear', 'text' => '⑤ブラッシングや爪切りなど、お手入れの道具を見ただけで逃げ出す'],
            'Q34' => ['category' => 'fear', 'text' => '⑥ 散歩中、大きなトラックの音やバイクの音に驚いてパニックになる'],
            'Q35' => ['category' => 'fear', 'text' => '⑦傘がバッと開く音や、急に動くものに対して過剰に驚く'],
            'Q36' => ['category' => 'trainability', 'text' => '①「オスワリ」や「マテ」などの指示に、一回でスムーズに従う'],
            'Q37' => ['category' => 'trainability', 'text' => '②散歩中、名前を呼んだらすぐに飼い主の顔を見る'],
            'Q38' => ['category' => 'trainability', 'text' => '③ 外の環境（他の犬や人）が気になっても、飼い主の声がしっかり届く'],
            'Q39' => ['category' => 'trainability', 'text' => '④新しい芸やルールを、数回教えただけで覚える'],
            'Q40' => ['category' => 'trainability', 'text' => '⑤ 投げたボールやおもちゃを、ちゃんと手元まで持ってこれる'],
            'Q41' => ['category' => 'trainability', 'text' => '⑥落ちているものを食べようとした時、「出せ」や「ダメ」がすぐきく'],
            'Q42' => ['category' => 'trainability', 'text' => '⑦ 散歩中、リードをピンと張らずに楽しそうに横を歩ける'],
            'Q43' => ['category' => 'excitability', 'text' => '① お客さんが来ると、飛びついたり走り回ったりして大騒ぎになる'],
            'Q44' => ['category' => 'excitability', 'text' => '② 散歩に行く準備をすると、興奮して吠えたり飛び跳ねたりが止まらない'],
            'Q45' => ['category' => 'excitability', 'text' => '③遊び始めるとテンションが上がりすぎて、甘噛みが本気になってくる'],
            'Q46' => ['category' => 'excitability', 'text' => '④ 理由もなく、突然スイッチが入ったように家中を猛ダッシュする'],
            'Q47' => ['category' => 'excitability', 'text' => '⑤ 玄関やサークルの扉が開くと、ロケットのように飛び出そうとする'],
            'Q48' => ['category' => 'excitability', 'text' => '⑥ 他の犬と遊ぶ時、相手が「やめて」と言っていてもお構いなしに遊び続ける'],
            'Q49' => ['category' => 'excitability', 'text' => '⑦1日中ほとんど寝ずに、ずっと動いたり何かを探したりしている'],
            'Q50' => ['category' => 'excitability', 'text' => '⑧ 散歩から帰ってきても、体力が有り余っていてすぐに遊びたがる'],
        ];
    }

    /**
     * 設問の版ごとの 50 問（ID → カテゴリの値・設問の文）。定義のない版なら null（2026-10 追加）。
     * 結果（D-1200）を表示するときは、結果の question_version に合った設問の文で出す（S-0309）。
     * 今の版は v1 だけ。設問の文を直して版を上げるときは、前の版の設問もここで返せるように残すこと
     *
     * @return array<string, array{category: string, text: string}>|null
     */
    public static function questionsFor(string $version): ?array
    {
        return match ($version) {
            self::VERSION => self::questions(),
            default => null,
        };
    }

    /**
     * カテゴリごとの設問の ID（カテゴリの値 → ID の一覧）。並びは categories() と同じ
     *
     * @return array<string, list<string>>
     */
    public static function questionIdsByCategory(): array
    {
        $ids = array_fill_keys(array_keys(self::categories()), []);
        foreach (self::questions() as $id => $question) {
            $ids[$question['category']][] = $id;
        }

        return $ids;
    }

    /**
     * 選択肢（回答の値 → 表示）。全問共通。この並びで画面に出す（db-schema.md 5-25.）
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            '0' => '0：全くない',
            '1' => '1：ほとんどない',
            '2' => '2：時々ある',
            '3' => '3：よくある',
            '4' => '4：常にある',
            self::NOT_EXPERIENCED => '未経験',
        ];
    }

    /**
     * タイプ（値 → 表示名。db-schema.md 5-24.）
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            'guardian' => '慎重派・しっかり番犬タイプ',
            'affectionate' => '愛情たっぷり・甘えん坊タイプ',
            'sensitive' => '繊細・デリケートタイプ',
            'smart' => '聡明・スマート優等生タイプ',
            'energetic' => 'エネルギッシュ・アスリートタイプ',
            'calm' => 'のんびり・平和主義タイプ',
        ];
    }

    /**
     * いちばん高いカテゴリ → タイプの値（要件定義書 6-16「性格診断の採点とタイプの判定」）。
     * いちばん高い平均が 1.5 未満のときの calm は PersonalityDiagnosisScorer で決める
     *
     * @return array<string, string>
     */
    public static function typeByCategory(): array
    {
        return [
            'people' => 'guardian',
            'dogs' => 'guardian',
            'possessiveness' => 'affectionate',
            'separation' => 'affectionate',
            'fear' => 'sensitive',
            'trainability' => 'smart',
            'excitability' => 'energetic',
        ];
    }
}
