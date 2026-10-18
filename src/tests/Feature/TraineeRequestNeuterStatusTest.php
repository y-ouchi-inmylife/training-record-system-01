<?php

namespace Tests\Feature;

use App\Http\Requests\TraineeRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * トレーニーの去勢・避妊（neuter_status）の値の制限（TraineeRequest。2026-10 追加）。
 * 値は db-schema.md 5-21（neutered / not_neutered / unknown / 空）。
 */
class TraineeRequestNeuterStatusTest extends TestCase
{
    private function errors(mixed $neuterStatus): array
    {
        $request = TraineeRequest::create('/trainees', 'POST', ['name' => 'モモ', 'neuter_status' => $neuterStatus]);
        $request->setContainer($this->app);

        $validator = Validator::make($request->all(), $request->rules(), [], $request->attributes());
        $request->withValidator($validator);
        $validator->passes();

        return $validator->errors()->get('neuter_status');
    }

    public function test_決められた値と空はエラーにならない(): void
    {
        foreach (['neutered', 'not_neutered', 'unknown', null] as $value) {
            $this->assertSame([], $this->errors($value), var_export($value, true));
        }
    }

    public function test_決められた値以外はエラーになる(): void
    {
        // dont_know（当初の愛犬アンケートの回答の「分からない」。2026-10 にやめた）は、トレーニーの値には使わない
        foreach (['dont_know', 'yes', 'male'] as $value) {
            $this->assertSame(['選択された去勢・避妊は有効ではありません。'], $this->errors($value), $value);
        }
    }
}
