import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    applyYearLabels,
    measurementTickLabel,
    measurementTickLabelReserved,
    measurementTickLabels,
} from '../../resources/js/measurement-chart-ticks.js';

// 体重推移グラフ（会員側 S-1402・トレーナー側 S-0309 で共有の measurement-chart.js）の横軸の目盛りの文字（2026-10）。
// すべて日付（M/d）。左端と、年が変わってから最初の目盛りだけ 2 行目に年（yyyy）。
// 年を付けるかどうかは、間引いた（autoSkip）あとの、画面に出ている目盛りで決める。

// ローカル時間で目盛りの位置（1 日の始まり 0:00）を作る（Chart.js の time スケールが渡す値と同じくミリ秒）
const at = (y, m, d, h = 0, min = 0) => new Date(y, m - 1, d, h, min).getTime();

describe('体重推移グラフの横軸の目盛りの文字', () => {
    it('日付は M/d（月・日の先頭を 0 で埋めない）。時刻は出さない', () => {
        expect(measurementTickLabel(at(2026, 9, 29))).toBe('9/29');
        expect(measurementTickLabel(at(2026, 10, 1))).toBe('10/1');
        expect(measurementTickLabel(at(2026, 10, 15, 18, 30))).toBe('10/15');
    });

    it('年をまたがない並び：左端だけ年を出し、ほかは日付だけ', () => {
        expect(measurementTickLabels([at(2026, 9, 21), at(2026, 9, 28), at(2026, 10, 5)])).toEqual([
            ['9/21', '2026'],
            '9/28',
            '10/5',
        ]);
    });

    it('年をまたぐ並び（12/28, 1/4, 1/11）：左端と 1/4 に年', () => {
        expect(measurementTickLabels([at(2026, 12, 28), at(2027, 1, 4), at(2027, 1, 11)])).toEqual([
            ['12/28', '2026'],
            ['1/4', '2027'],
            '1/11',
        ]);
    });

    it('間引いたあとの並びで 1/1 が間引かれていても、年が変わってから最初に出ている目盛りに年が付く', () => {
        // 間引く前：12/25, 12/28, 12/31, 1/1, 1/3, 1/6 … → 間引いたあと（3 つおき）：12/25, 1/1 は消え 12/31, 1/6
        const afterSkip = [at(2026, 12, 25), at(2026, 12, 31), at(2027, 1, 6), at(2027, 1, 12)];

        expect(measurementTickLabels(afterSkip)).toEqual([
            ['12/25', '2026'],
            '12/31',
            ['1/6', '2027'],
            '1/12',
        ]);
    });

    it('目盛りが 1 つだけなら、その目盛りに年', () => {
        expect(measurementTickLabels([at(2026, 10, 10)])).toEqual([['10/10', '2026']]);
    });

    it('2 年以上またぐ並び（2026→2027→2028）：それぞれの年の最初の目盛りに年', () => {
        expect(measurementTickLabels([
            at(2026, 11, 1), at(2026, 12, 1), at(2027, 1, 1), at(2027, 6, 1), at(2028, 1, 1), at(2028, 2, 1),
        ])).toEqual([
            ['11/1', '2026'],
            '12/1',
            ['1/1', '2027'],
            '6/1',
            ['1/1', '2028'],
            '2/1',
        ]);
    });

    it('ticks.callback（間引く前）では、すべての目盛りに 2 行を付けて横軸の高さを 2 行分取る', () => {
        expect(measurementTickLabelReserved(at(2026, 12, 28))).toEqual(['12/28', '2026']);
        expect(measurementTickLabelReserved(at(2027, 1, 4))).toEqual(['1/4', '2027']);
    });

    it('afterFit（間引いたあと）で、画面に出ている目盛りの文字を付け直す', () => {
        // Chart.js の軸のうち、使うのは ticks（間引いたあとの、画面に出る目盛り。value はミリ秒）だけ
        const axis = {
            ticks: [at(2026, 12, 20), at(2026, 12, 27), at(2027, 1, 3), at(2027, 1, 10)]
                .map((value) => ({ value, label: measurementTickLabelReserved(value) })),
        };

        applyYearLabels(axis);

        expect(axis.ticks.map((t) => t.label)).toEqual([['12/20', '2026'], '12/27', ['1/3', '2027'], '1/10']);
    });

    it('measurement-chart.js は、callback で 2 行を取り afterFit で付け直す。粒度とツールチップの書式は今のまま', () => {
        const src = readFileSync(new URL('../../resources/js/measurement-chart.js', import.meta.url), 'utf8');

        expect(src).toContain("import { applyYearLabels, measurementTickLabelReserved } from './measurement-chart-ticks';");
        expect(src).toContain('return measurementTickLabelReserved(value);');
        expect(src).toContain('applyYearLabels(axis);');
        expect(src).not.toContain("format(d, 'H:mm')");
        expect(src).toContain("unit: 'day'");
        expect(src).toContain("tooltipFormat: 'yyyy/M/d HH:mm'");
        expect(src).toContain('autoSkip: true');
    });

    it('ツールチップはグラフ内のどこを触っても、横方向にいちばん近い点に出す（2026-10-28 変更）', () => {
        const src = readFileSync(new URL('../../resources/js/measurement-chart.js', import.meta.url), 'utf8');

        // グラフごとの options に置く（Chart.defaults は変えない。レーダーチャートに影響させない）
        expect(src).toMatch(/interaction: \{\s*mode: 'nearest',\s*axis: 'x',\s*intersect: false,\s*\}/);
        expect(src).not.toMatch(/Chart\.defaults\.[\w.]+\s*=/);
        // tooltip 側で mode・intersect を指定して interaction を打ち消さない
        const tooltip = src.slice(src.indexOf('tooltip: {'), src.indexOf('scales: {'));
        expect(tooltip).not.toMatch(/\bmode:|\bintersect:/);
    });
});
