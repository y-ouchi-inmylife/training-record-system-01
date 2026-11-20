{{--
    メディアを選ぶモーダルのカードの「トレーニー・会員」の行を作る JS（2026-11 追加。設計書 S-0401-M02・S-0404-M01・S-0309-M02）

    メディア一覧（S-1302）のカード（media-records/index.blade.php。Blade で作っている）と同じ見た目の行を、
    候補の API（available-media）の linked_labels / linked_others_count から JS で作る。
    - 区切り線＋「肉球＋トレーニー名：会員名」を 1 人 1 行（最大 3 行。API が 3 件までに切って返す）
    - 4 人以上は 4 行目に「ほか N名」（肉球なし・名前の先頭にそろえる字下げ・text-muted）
    - ひも付きがなければ、区切り線も肉球も出さず、同じ太さの透明な線と同じ余白で 1 行分の高さを取る
    - 名前は textContent で入れる（innerHTML に名前を入れない）。ツールチップは付けない
    クラスと字下げの値は media-records/index.blade.php のカードと同じにすること（片方だけ変えない）。

    肉球の形の元は <x-icon-paw /> の 1 か所だけにするため、ここで <template> に 1 つ出しておき、JS で複製する
    （JS の中に SVG の文字列を書き写さない）。

    メディア追加モーダル（training-records/_form.blade.php）とトレーナー用写真の選択モーダル
    （trainees/_trainer-photo-modal.blade.php）の両方が @push('scripts') の中でこのパーシャルを読み込み、
    window.buildMediaLinkedClients(m) を呼ぶ（2 つのモーダルで同じ関数を使う。@once で 1 ページに 1 回だけ出す）。
--}}
@once
<template id="mediaLinkedPawIconTemplate"><x-icon-paw class="text-primary flex-shrink-0" /></template>
<script>
window.buildMediaLinkedClients = function (m) {
    const labels = Array.isArray(m.linked_labels) ? m.linked_labels : [];
    const othersCount = Number(m.linked_others_count) || 0;
    const wrap = document.createElement('div');

    if (labels.length === 0) {
        // ひも付きなし：区切り線の分も含めて 1 行分の高さだけを取る（§2-4 の空欄。読み上げからは外す）
        wrap.className = 'media-card-trainees mt-1 pt-1';
        wrap.style.borderTop = 'var(--bs-border-width) solid transparent';
        wrap.setAttribute('aria-hidden', 'true');
        wrap.textContent = ' ';
        return wrap;
    }

    wrap.className = 'media-card-trainees mt-1 pt-1 border-top';
    const pawTemplate = document.getElementById('mediaLinkedPawIconTemplate');
    labels.forEach(function (label) {
        const row = document.createElement('div');
        row.className = 'd-flex align-items-center gap-1';
        if (pawTemplate && pawTemplate.content.firstElementChild) {
            row.appendChild(pawTemplate.content.firstElementChild.cloneNode(true));
        }
        const name = document.createElement('span');
        name.className = 'text-truncate';
        name.style.minWidth = '0';
        name.textContent = label;
        row.appendChild(name);
        wrap.appendChild(row);
    });

    if (othersCount > 0) {
        // 肉球の幅（1em）＋隙間（gap-1 = 0.25rem）の分だけ字下げして、上の行の名前の先頭にそろえる
        const others = document.createElement('div');
        others.className = 'media-card-others text-muted';
        others.style.paddingLeft = 'calc(1em + 0.25rem)';
        others.textContent = 'ほか' + othersCount + '名';
        wrap.appendChild(others);
    }

    return wrap;
};
</script>
@endonce
