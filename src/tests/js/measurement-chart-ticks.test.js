import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { measurementTickLabel } from '../../resources/js/measurement-chart-ticks.js';

// 体重推移グラフ（会員側 S-1402・トレーナー側 S-0309 で共有の measurement-chart.js）の横軸の目盛りの文字（2026-10 変更）。
// 最初の目盛りも含めてすべて日付だけ（M/d）。当初は最初の目盛りだけ 2 行目に時刻（「0:00」）を出していた。

// ローカル時間で目盛りの位置（1 日の始まり 0:00）を作る（Chart.js の time スケールが渡す値と同じくミリ秒）
const at = (y, m, d, h = 0, min = 0) => new Date(y, m - 1, d, h, min).getTime();

describe('体重推移グラフの横軸の目盛りの文字', () => {
    it('日付だけ（M/d）。月・日は先頭を 0 で埋めない', () => {
        expect(measurementTickLabel(at(2026, 9, 29))).toBe('9/29');
        expect(measurementTickLabel(at(2026, 10, 1))).toBe('10/1');
        expect(measurementTickLabel(at(2026, 12, 31))).toBe('12/31');
    });

    it('最初の目盛りも含めて、どの目盛りも時刻を出さない（文字列 1 行）', () => {
        // Chart.js は callback を (value, index, ticks) で呼ぶ。index に関わらず同じ書式
        const ticks = [at(2026, 9, 29), at(2026, 9, 30), at(2026, 10, 1)];
        ticks.forEach((value, index) => {
            const label = measurementTickLabel(value, index, ticks);
            expect(typeof label).toBe('string');
            expect(label).not.toMatch(/:/);
            expect(label).toMatch(/^\d{1,2}\/\d{1,2}$/);
        });
        expect(measurementTickLabel(ticks[0], 0, ticks)).toBe('9/29');
    });

    it('時刻を含む値（同じ日の朝と夕）でも日付だけ', () => {
        expect(measurementTickLabel(at(2026, 10, 15, 8, 0))).toBe('10/15');
        expect(measurementTickLabel(at(2026, 10, 15, 18, 30))).toBe('10/15');
    });

    it('measurement-chart.js の横軸の目盛りはこの関数を使い、時刻の 2 行目を出す作りは残っていない', () => {
        const src = readFileSync(new URL('../../resources/js/measurement-chart.js', import.meta.url), 'utf8');

        expect(src).toContain("import { measurementTickLabel } from './measurement-chart-ticks';");
        expect(src).toContain('return measurementTickLabel(value);');
        expect(src).not.toContain("format(d, 'H:mm')");
        expect(src).not.toMatch(/index === 0/);
        // 目盛りの粒度（日単位）とツールチップの書式は今のまま
        expect(src).toContain("unit: 'day'");
        expect(src).toContain("tooltipFormat: 'yyyy/M/d HH:mm'");
    });
});
