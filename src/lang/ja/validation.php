<?php

return [

    /*
    |--------------------------------------------------------------------------
    | バリデーション言語行
    |--------------------------------------------------------------------------
    |
    | 以下の言語行はバリデータクラスが使用するデフォルトのエラーメッセージを
    | 含みます。いくつかのルールには、サイズ違反に対して複数のバージョンが
    | あります。メッセージは自由に調整できます。
    |
    */

    'accepted' => ':attributeを承認してください。',
    'accepted_if' => ':otherが:valueのとき、:attributeを承認してください。',
    'active_url' => ':attributeは有効なURLではありません。',
    'after' => ':attributeは:date以降の日付で入力してください。',
    'after_or_equal' => ':attributeは:date以降の日付で入力してください。',
    'alpha' => ':attributeはアルファベットのみ使用できます。',
    'alpha_dash' => ':attributeはアルファベット、ハイフン、アンダースコア、数字が使用できます。',
    'alpha_num' => ':attributeはアルファベットと数字のみ使用できます。',
    'any_of' => ':attributeは有効ではありません。',
    'array' => ':attributeは配列でなければなりません。',
    'ascii' => ':attributeは半角英数字と記号のみ使用できます。',
    'before' => ':attributeには:date以前の日付を指定してください。',
    'before_or_equal' => ':attributeは:date以前の日付で入力してください。',
    'between' => [
        'array' => ':attributeの項目は:min〜:max個にしてください。',
        'file' => ':attributeは:min〜:maxキロバイトのファイルにしてください。',
        'numeric' => ':attributeは:min〜:maxの範囲で指定してください。',
        'string' => ':attributeは:min〜:max文字で入力してください。',
    ],
    'boolean' => ':attributeはtrueかfalseを指定してください。',
    'can' => ':attributeには許可されていない値が含まれています。',
    'confirmed' => ':attribute（確認）が一致しません。',
    'contains' => ':attributeに必要な値が含まれていません。',
    'current_password' => '現在のパスワードが正しくありません。',
    'date' => ':attributeの形式が正しくありません。',
    'date_equals' => ':attributeは:dateと同じ日付にしてください。',
    'date_format' => ':attributeの形式が正しくありません。',
    'decimal' => ':attributeは小数点以下:decimal桁までで入力してください。',
    'declined' => ':attributeは拒否されなければなりません。',
    'declined_if' => ':otherが:valueのとき、:attributeは拒否されなければなりません。',
    'different' => ':attributeは:otherと別のものを選択してください。',
    'digits' => ':attributeは:digits桁で指定してください。',
    'digits_between' => ':attributeは:min〜:max桁で指定してください。',
    'dimensions' => ':attributeの画像サイズが無効です。',
    'distinct' => ':attributeには異なる値を指定してください。',
    'doesnt_end_with' => ':attributeは次のいずれかで終わってはいけません。:values',
    'doesnt_start_with' => ':attributeは次のいずれかで始まってはいけません。:values',
    'email' => ':attributeの形式が正しくありません。',
    'ends_with' => ':attributeは:valuesのいずれかで終わる必要があります。',
    'enum' => '選択された:attributeは有効ではありません。',
    'exists' => '選択された:attributeが存在しません。',
    'extensions' => ':attributeは次の拡張子のいずれかである必要があります。:values',
    'file' => ':attributeを選択し直してください。',
    'filled' => ':attributeを指定してください。',
    'gt' => [
        'array' => ':attributeの項目は:value個より多く指定してください。',
        'file' => ':attributeは:valueキロバイトより大きくしてください。',
        'numeric' => ':attributeは:valueより大きい値で入力してください。',
        'string' => ':attributeは:valueより大きい文字数で指定してください。',
    ],
    'gte' => [
        'array' => ':attributeの項目は:value個以上指定してください。',
        'file' => ':attributeは:valueキロバイト以上にしてください。',
        'numeric' => ':attributeは:value以上の値を指定してください。',
        'string' => ':attributeは:value文字以上で指定してください。',
    ],
    'hex_color' => ':attributeは有効な16進数カラーコードである必要があります。',
    'image' => ':attributeには画像ファイルを指定してください。',
    'in' => '選択された:attributeは有効ではありません。',
    'in_array' => ':attributeには:otherに含まれる値を指定してください。',
    'in_array_keys' => ':attributeには次のキーのいずれかを含める必要があります。:values',
    'integer' => ':attributeは整数で入力してください。',
    'ip' => ':attributeは有効なIPアドレス形式で指定してください。',
    'ipv4' => ':attributeは有効なIPv4アドレス形式で指定してください。',
    'ipv6' => ':attributeは有効なIPv6アドレス形式で指定してください。',
    'json' => ':attributeは有効なJSON形式で指定してください。',
    'list' => ':attributeはリストでなければなりません。',
    'lowercase' => ':attributeは小文字で指定してください。',
    'lt' => [
        'array' => ':attributeの項目は:value個より少なく指定してください。',
        'file' => ':attributeは:valueキロバイトより小さくしてください。',
        'numeric' => ':attributeは:valueより小さい値を指定してください。',
        'string' => ':attributeは:valueより少ない文字数で指定してください。',
    ],
    'lte' => [
        'array' => ':attributeの項目は:value個以下にしてください。',
        'file' => ':attributeは:valueキロバイト以下にしてください。',
        'numeric' => ':attributeは:value以下の値を指定してください。',
        'string' => ':attributeは:value文字以下で指定してください。',
    ],
    'mac_address' => ':attributeは有効なMACアドレスではありません。',
    'max' => [
        'array' => ':attributeの項目は:max個以下にしてください。',
        'file' => ':attributeは:maxキロバイト以下にしてください。',
        'numeric' => ':attributeは:max以下で入力してください。',
        'string' => ':attributeは:max文字以内で入力してください。',
    ],
    'max_digits' => ':attributeの桁数は:max桁以下にしてください。',
    'mimes' => ':attributeの形式が正しくありません。',
    'mimetypes' => ':attributeは:valuesタイプのファイルにしてください。',
    'min' => [
        'array' => ':attributeは:min個以上指定してください。',
        'file' => ':attributeは:minキロバイト以上にしてください。',
        'numeric' => ':attributeは:min以上で入力してください。',
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'min_digits' => ':attributeの桁数は:min桁以上にしてください。',
    'missing' => ':attributeは存在してはいけません。',
    'missing_if' => ':otherが:valueのとき、:attributeは存在してはいけません。',
    'missing_unless' => ':otherが:valueの場合を除いて、:attributeは存在してはいけません。',
    'missing_with' => ':valuesが存在する場合、:attributeは存在してはいけません。',
    'missing_with_all' => ':valuesが存在する場合、:attributeは存在してはいけません。',
    'multiple_of' => ':attributeは:valueの倍数でなければなりません。',
    'not_in' => '選択された:attributeは有効ではありません。',
    'not_regex' => ':attributeの形式が無効です。',
    'numeric' => ':attributeは数値で入力してください。',
    'password' => [
        'letters' => ':attributeには英字を含める必要があります。',
        'mixed' => ':attributeには大文字と小文字を含める必要があります。',
        'numbers' => ':attributeには数字を含める必要があります。',
        'symbols' => ':attributeには記号を含める必要があります。',
        'uncompromised' => '指定された:attributeは漏洩しています。別の:attributeを指定してください。',
    ],
    'present' => ':attributeを指定してください。',
    'present_if' => ':otherが:valueのとき、:attributeを指定してください。',
    'present_unless' => ':otherが:value以外のとき、:attributeを指定してください。',
    'present_with' => ':valuesが指定されたとき、:attributeを指定してください。',
    'present_with_all' => ':valuesがすべて指定されたとき、:attributeを指定してください。',
    'prohibited' => ':attributeを指定することはできません。',
    'prohibited_if' => ':otherが:valueのとき、:attributeを指定することはできません。',
    'prohibited_if_accepted' => ':otherが承認されたとき、:attributeを指定することはできません。',
    'prohibited_if_declined' => ':otherが拒否されたとき、:attributeを指定することはできません。',
    'prohibited_unless' => ':otherが:valuesに含まれていない限り、:attributeを指定することはできません。',
    'prohibits' => ':attributeが存在するとき、:otherを指定することはできません。',
    'regex' => ':attributeの形式が正しくありません。',
    'required' => ':attributeを入力してください。',
    'required_array_keys' => ':attributeには:valuesのキーを含める必要があります。',
    'required_if' => ':otherが:valueのとき、:attributeを指定してください。',
    'required_if_accepted' => ':otherが承認された場合、:attributeを指定してください。',
    'required_if_declined' => ':otherが拒否された場合、:attributeを指定してください。',
    'required_unless' => ':otherが:valuesでない限り、:attributeを指定してください。',
    'required_with' => ':valuesが指定されているとき、:attributeも指定してください。',
    'required_with_all' => ':valuesがすべて指定されているとき、:attributeも指定してください。',
    'required_without' => ':valuesが指定されていないとき、:attributeを指定してください。',
    'required_without_all' => ':valuesがどれも指定されていないとき、:attributeを指定してください。',
    'same' => ':attributeと:otherが一致しません。',
    'size' => [
        'array' => ':attributeは:size個指定してください。',
        'file' => ':attributeは:sizeキロバイトでなければなりません。',
        'numeric' => ':attributeは:sizeを指定してください。',
        'string' => ':attributeは:size文字で指定してください。',
    ],
    'starts_with' => ':attributeは:valuesのいずれかで始まる必要があります。',
    'string' => ':attributeは文字列で入力してください。',
    'timezone' => ':attributeは有効なタイムゾーンで指定してください。',
    'unique' => 'この:attributeはすでに使われています。',
    'uploaded' => ':attributeのアップロードに失敗しました。',
    'uppercase' => ':attributeは大文字で指定してください。',
    'url' => ':attributeは有効なURLを指定してください。',
    'ulid' => ':attributeは有効なULIDでなければなりません。',
    'uuid' => ':attributeは有効なUUIDでなければなりません。',

    /*
    |--------------------------------------------------------------------------
    | カスタムバリデーション言語行
    |--------------------------------------------------------------------------
    |
    | "attribute.rule" の規約で言語行を指定することで、特定の属性に対する
    | カスタムメッセージを指定できます。
    |
    */

    'custom' => [
        'last_name_kana' => [
            'regex' => 'せいはひらがなで入力してください。',
        ],
        'first_name_kana' => [
            'regex' => 'めいはひらがなで入力してください。',
        ],
        'trainer2_id' => [
            'different' => '担当2は担当1と異なるトレーナーを選択してください。',
        ],
        // 選択式項目の必須（§2-8「必須（選択）」）
        'address1' => [
            'required' => '都道府県を選択してください。',
        ],
        'role' => [
            'required' => '権限を選択してください。',
            // 画面の選択肢（「管理者」「一般」）を具体的に示す文言は in の既定より分かりやすい（§2-8「例外」）
            'in' => '権限は管理者または一般を選択してください。',
        ],
        'trainer1_id' => [
            'required' => '担当1を選択してください。',
        ],
        'client_id' => [
            'required' => '会員を選択してください。',
        ],
        // 項目どうしの関係（§2-8「項目どうしの関係」）
        'date_to' => [
            'after_or_equal' => '終了日は開始日以降の日付で入力してください。',
        ],
        // 複合キーの重複（trainee_id × measured_date × measured_time）。§2-8「項目どうしの関係」の派生
        'measured_time' => [
            'unique' => 'この日時の計測値はすでに登録されています。',
        ],
        // 具体的に何を直せばよいかが分かる文言（§2-8「例外」）：半角英数字とアンダースコアだけが使える仕様
        'login_id' => [
            'regex' => 'ログインIDに使用できない文字が含まれています。',
        ],
        // 会員側のメールアドレスの重複は、他人の登録の有無を分からせない文言にする（§2-8「例外」）
        'email' => [
            'unique' => 'このメールアドレスは登録できません。',
        ],
        'new_email' => [
            'unique' => 'このメールアドレスは登録できません。',
        ],
        // 会員側のトレーニー写真（画面ラベル「写真」）。file.mimes / file.max は固定値を含むため個別文言
        'photo' => [
            'required' => '写真を選択してください。',
            'mimes' => '写真は JPEG / PNG / HEIC / HEIF 形式のみ登録できます。',
            'max' => '写真のサイズは 20MB 以下にしてください。',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | カスタムバリデーション属性名
    |--------------------------------------------------------------------------
    |
    | 以下の言語行は ":attribute" プレースホルダを置き換えるときに、
    | システム上の属性名の代わりにユーザーフレンドリーな名前を表示するために
    | 使用します。
    |
    */

    'attributes' => [
        'last_name' => '姓',
        'first_name' => '名',
        'last_name_kana' => 'せい',
        'first_name_kana' => 'めい',
        'initial_consultation_date' => '初回日',
        'phone1' => '電話番号',
        'phone2' => '電話番号（予備）',
        'email' => 'メールアドレス',
        'new_email' => '新しいメールアドレス',
        'postal_code' => '郵便番号',
        'address1' => '都道府県',
        'address2' => '市区町村',
        'address3' => '町名・番地',
        // 画面のラベル（§2-8「項目名は、画面のラベルと同じにする」）
        'address4' => '建物名・部屋番号',
        'login_id' => 'ログインID',
        // 画面のラベル（画面ラベル「名前」。トレーナー氏名・トレーニー名の両方で「名前」）
        'name' => '名前',
        'password' => 'パスワード',
        'password_confirmation' => 'パスワード（確認）',
        'current_password' => '現在のパスワード',
        'new_password' => '新しいパスワード',
        'new_password_confirmation' => '新しいパスワード（確認）',
        'role' => '権限',
        'primary_trainer_id' => '主担当',
        'trainer1_id' => '担当1',
        'trainer2_id' => '担当2',
        'training_date' => 'トレーニング日',
        'training_time' => '時刻',
        'internal_id' => '内部ID',
        'date_from' => '開始日',
        'date_to' => '終了日',
        'photo' => '写真',
        'current_prompt' => '要約プロンプト',
        // 音声記録のコントローラーでも使うキーだが、そちらは個別の文言を持つため、ここでの追加では表示は変わらない
        // （段階 5 で音声記録・メディアの文言を扱うときに整合させる）
        'client_id' => '会員',
        'title' => '表示名',
        'transcription_text' => '文字起こしテキスト',
        'summary_text' => '要約',
    ],

];
