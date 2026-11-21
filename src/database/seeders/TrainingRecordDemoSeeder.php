<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\TrainingRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * デモ・段階3 検証用のトレーニング記録データ投入
 *
 * クライアントポータルのダッシュボード再構成（段階3）を検証するため、
 * 対象クライアント（佐藤 太郎、client@example.com）に 18 件の
 * トレーニング記録を投入する。フィード表示・日付ブロック・「続きを読む」
 * 展開・非開示フィールド（impression）のクライアント側での不露出 などを検証する。
 *
 * ================================================================
 * 【本番実行しないこと】
 *
 * このシーダーは DatabaseSeeder の $this->call([...]) に登録しない。
 * デモ・開発検証専用のため、明示指定で実行する：
 *
 *     php artisan db:seed --class=TrainingRecordDemoSeeder
 *
 * ================================================================
 * 【冪等性】
 *
 * 対象クライアントの既存 TrainingRecord を全削除してから 18 件を投入する。
 * training_records に自然キーがないため、削除方式が最も確実。
 *
 * 副作用: media_record_training_record の該当行も ON DELETE CASCADE で
 * 消える（MediaRecord 本体は残る）。デモデータのため許容範囲。
 * 削除対象は client@example.com に紐づく記録のみで、他クライアントの
 * 記録には影響しない。
 * ================================================================
 */
class TrainingRecordDemoSeeder extends Seeder
{
    public function run(): void
    {
        $client = Client::where('email', 'client@example.com')->first();
        if (!$client) {
            $this->command->warn(
                'クライアント（client@example.com）が見つかりません。' .
                'ClientSeeder を先に実行してください。何もせず終了します。'
            );
            return;
        }

        DB::transaction(function () use ($client) {
            // 既存の TrainingRecord を全削除
            // ON DELETE CASCADE により media_record_training_record の該当行も
            // 自動削除される（MediaRecord 本体は残る）
            $deleted = TrainingRecord::where('client_id', $client->id)->delete();
            $this->command->info("既存のトレーニング記録 {$deleted} 件を削除しました。");

            foreach ($this->records() as $rec) {
                TrainingRecord::create(array_merge(['client_id' => $client->id], $rec));
            }
        });

        $this->command->info('デモ用トレーニング記録 18 件を投入しました。');
    }

    /**
     * 投入する 18 件のデータ定義（training_date 昇順）
     *
     * 【ばらつきの設計】
     * - trainer1_id:      2（管理トレーナー）と 3（一般トレーナー）を交互に配置
     * - trainer2_id:      9 件 NULL、9 件はもう一方のトレーナー
     * - training_time:    15 件は時刻、3 件は NULL
     * - record_content:   長文(200文字以上) 6 件 / 短文(30〜60文字) 7 件 / NULL 5 件
     * - impression:       全 18 件に値（クライアント非開示フィールドの露出検証用）
     * - updated_by:       trainer1_id と同じ
     *
     * 【日付の分布】
     * 2026-02-03 〜 2026-08-02 の範囲、週次〜隔週の不均一な間隔。
     * ダッシュボードは training_date 降順で表示するため、最新は 2026-08-02。
     */
    private function records(): array
    {
        return [
            // 1件目（最古）
            [
                'training_date'    => '2026-02-03',
                'training_time'    => null,
                'trainer1_id'      => 2,
                'trainer2_id'      => null,
                'record_content'   => null,
                'impression'       => '初回に近い緊張感あり。ご褒美のタイミングを飼い主さんに具体的に共有した方がよい。',
                'updated_by'       => 2,
            ],
            // 2件目 - 短文
            [
                'training_date'    => '2026-02-11',
                'training_time'    => '10:00:00',
                'trainer1_id'      => 3,
                'trainer2_id'      => 2,
                'record_content'   => 'おすわりの反応が安定してきました。3 秒以内で確実に座れます。',
                'impression'       => '飼い主さんが指示の直後にご褒美を出せるようになった。次回から間隔を空ける段階へ。',
                'updated_by'       => 3,
            ],
            // 3件目 - 長文
            [
                'training_date'    => '2026-02-24',
                'training_time'    => '13:30:00',
                'trainer1_id'      => 2,
                'trainer2_id'      => null,
                'record_content'   => '今日は「まて」の姿勢を安定させる練習を中心に行いました。前回よりも待機時間が長くなり、集中が続くようになっています。玄関のインターホン音でも姿勢を崩さず 30 秒間キープできました。次回は環境刺激をもう一段階上げて、玄関を開ける動作の中でも「まて」を維持できるかを試していきたいと思います。おやつの頻度も少しずつ減らしていける段階に入りそうです。ご自宅でも短時間から毎日反復していただけると定着が早まりますので、無理のない範囲で続けてみてください。',
                'impression'       => '「まて」の環境刺激耐性がついてきた。次回は玄関開閉を含めた実地訓練へ進めたい。',
                'updated_by'       => 2,
            ],
            // 4件目 - 短文
            [
                'training_date'    => '2026-03-04',
                'training_time'    => '10:30:00',
                'trainer1_id'      => 3,
                'trainer2_id'      => 2,
                'record_content'   => 'ハウストレーニング、ケージ内で 15 分静かに過ごせました。',
                'impression'       => 'ケージ内での安定は環境依存。ご自宅でも同じ場所で継続してもらう。',
                'updated_by'       => 3,
            ],
            // 5件目 - NULL
            [
                'training_date'    => '2026-03-14',
                'training_time'    => '14:00:00',
                'trainer1_id'      => 2,
                'trainer2_id'      => null,
                'record_content'   => null,
                'impression'       => '犬側のコンディションは良好。飼い主さんが焦り気味なので、声のトーンを落として指示するようお伝えする。',
                'updated_by'       => 2,
            ],
            // 6件目 - 長文
            [
                'training_date'    => '2026-03-21',
                'training_time'    => '10:00:00',
                'trainer1_id'      => 3,
                'trainer2_id'      => 2,
                'record_content'   => 'リードの引っ張りへの対応をトレーニングしました。前を歩きたがるタイミングで立ち止まる練習を繰り返し、飼い主さんの隣を歩ける時間が徐々に増えてきました。特に公園までの往路では以前より明らかに落ち着いて歩けています。復路は疲れもあってか少し引っ張り気味だったので、次回は復路の対応を重点的に見ていきます。ご自宅の周辺での短い散歩でも同じ立ち止まりの練習を続けてみてください。毎日 10 分でも継続すると効果が出やすいです。',
                'impression'       => 'リード引っ張りは復路が課題。次回は復路開始時に「ついて」を強めに練習してみる。',
                'updated_by'       => 3,
            ],
            // 7件目 - 短文
            [
                'training_date'    => '2026-04-01',
                'training_time'    => '11:00:00',
                'trainer1_id'      => 2,
                'trainer2_id'      => null,
                'record_content'   => 'ボール遊びを介した集中力の練習。5 分間目が離れませんでした。',
                'impression'       => '集中は 5 分が限度。それ以上は疲労が出るので短時間反復に切り替える。',
                'updated_by'       => 2,
            ],
            // 8件目 - NULL
            [
                'training_date'    => '2026-04-11',
                'training_time'    => null,
                'trainer1_id'      => 3,
                'trainer2_id'      => 2,
                'record_content'   => null,
                'impression'       => 'マーキング衝動は季節要因（発情期）もありそう。無理に抑えず経過観察の方針。',
                'updated_by'       => 3,
            ],
            // 9件目 - 短文
            [
                'training_date'    => '2026-04-18',
                'training_time'    => '13:00:00',
                'trainer1_id'      => 2,
                'trainer2_id'      => null,
                'record_content'   => 'リードなしでの近距離アイコンタクトが確実に向上してきています。',
                'impression'       => 'アイコンタクトは飼い主さんとの関係性が良い証拠。ここは褒めて伸ばす。',
                'updated_by'       => 2,
            ],
            // 10件目 - 長文
            [
                'training_date'    => '2026-04-29',
                'training_time'    => '10:30:00',
                'trainer1_id'      => 3,
                'trainer2_id'      => 2,
                'record_content'   => '「おすわり」→「ふせ」→「まて」の連続動作の練習を中心に行いました。指示の切り替えに戸惑う場面がありましたが、繰り返すうちに反応が徐々に速くなってきました。特に「ふせ」から「まて」への移行がスムーズになった点が今日の一番の収穫です。指示語を出すタイミングを少しずつ短くしても、犬側が置いていかれずについてきています。ご自宅でも 5 分程度、短時間でよいので毎日反復していただけると定着が早まりますので、ぜひ続けてみてください。',
                'impression'       => '連続動作の切り替えで戸惑いあり。指示語の間隔を意図的に短くする方針で継続。',
                'updated_by'       => 3,
            ],
            // 11件目 - 短文
            [
                'training_date'    => '2026-05-13',
                'training_time'    => '11:00:00',
                'trainer1_id'      => 2,
                'trainer2_id'      => null,
                'record_content'   => '食事前の「まて」を 15 秒までキープできるようになりました。',
                'impression'       => '食事前は成功しやすい。他のシチュエーションへの汎化を次回検証。',
                'updated_by'       => 2,
            ],
            // 12件目 - NULL
            [
                'training_date'    => '2026-05-23',
                'training_time'    => '14:00:00',
                'trainer1_id'      => 3,
                'trainer2_id'      => 2,
                'record_content'   => null,
                'impression'       => '今日は軽めに。犬の疲労を優先し無理させない。次回本格再開の予定。',
                'updated_by'       => 3,
            ],
            // 13件目 - 長文
            [
                'training_date'    => '2026-06-03',
                'training_time'    => '10:00:00',
                'trainer1_id'      => 2,
                'trainer2_id'      => null,
                'record_content'   => '他の犬とのすれ違いに向けた社会化トレーニングを行いました。今日は 3 回ほかの犬とすれ違いましたが、うち 2 回は飼い主さんの方を見て集中を保つことができました。1 回は吠えかけそうになりましたが、名前を呼ぶことで意識を戻すことができています。少しずつですが、確実に落ち着いて対応できる場面が増えてきています。次回は同じルートで再挑戦し、反応の変化を見ていきます。散歩コースを少し変えてみるのも良さそうです。',
                'impression'       => '他の犬とのすれ違いは 3 回中 2 回 OK。まだ 1 回は反応があったので、その傾向を家庭でも観察してもらう。',
                'updated_by'       => 2,
            ],
            // 14件目 - 短文
            [
                'training_date'    => '2026-06-13',
                'training_time'    => '13:30:00',
                'trainer1_id'      => 3,
                'trainer2_id'      => 2,
                'record_content'   => 'ドッグランで他の犬たちと穏やかに交流できました。目立った緊張はありませんでした。',
                'impression'       => 'ドッグラン後の落ち着き方が早くなった。社会化は順調に進んでいる。',
                'updated_by'       => 3,
            ],
            // 15件目 - NULL
            [
                'training_date'    => '2026-06-27',
                'training_time'    => null,
                'trainer1_id'      => 2,
                'trainer2_id'      => null,
                'record_content'   => null,
                'impression'       => '散歩中の姿勢が全体に良くなった。飼い主さんへの労いも忘れずに伝える。',
                'updated_by'       => 2,
            ],
            // 16件目 - 長文
            [
                'training_date'    => '2026-07-11',
                'training_time'    => '10:30:00',
                'trainer1_id'      => 3,
                'trainer2_id'      => 2,
                'record_content'   => '「呼び戻し」の距離を段階的に伸ばす練習を、公園の広場で行いました。最初は 10 メートルの距離から始め、最終的に 25 メートル離れた地点からでも一度で戻ってこられました。周囲に他の犬や人がいる中でこの反応は大きな成長です。次回はロングリードを使って距離をもう少し伸ばしつつ、他の刺激下での安定を確認したいと思います。飼い主さんの声かけのトーンが安定してきたのも今日の良い変化でした。この調子で続けていきましょう。',
                'impression'       => '呼び戻しは距離が伸びた。ロングリード導入は次回から本格化する。',
                'updated_by'       => 3,
            ],
            // 17件目 - 短文
            [
                'training_date'    => '2026-07-25',
                'training_time'    => '11:00:00',
                'trainer1_id'      => 2,
                'trainer2_id'      => null,
                'record_content'   => '初対面の人への吠えは、環境設定を工夫することで対応可能な兆しあり。',
                'impression'       => '初対面の人への吠えは環境設定ができれば対応可能。実際の来客での再確認を推奨。',
                'updated_by'       => 2,
            ],
            // 18件目（最新） - 長文
            [
                'training_date'    => '2026-08-02',
                'training_time'    => '10:00:00',
                'trainer1_id'      => 3,
                'trainer2_id'      => 2,
                'record_content'   => 'これまで練習してきた「おすわり」「まて」「呼び戻し」「リード歩行」を通しで実施しました。全体を通して安定した反応が返ってきており、半年前と比べて明らかに集中の持続時間が長くなっています。特に「まて」の姿勢は 1 分以上キープでき、周囲の刺激にも動じませんでした。呼び戻しも距離を伸ばした状態で確実に応じてくれています。今後は日常生活の中で自然に指示に従えるよう、家庭での短時間反復を継続してください。',
                'impression'       => '全体的にトレーニング効果が定着してきた。継続を強く推奨。今日は特に集中力が高かった。',
                'updated_by'       => 3,
            ],
        ];
    }
}
