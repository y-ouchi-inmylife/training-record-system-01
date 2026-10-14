<?php

namespace Tests\Feature;

use App\Http\Requests\TraineeRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * トレーニーの名前は任意（TraineeRequest。2026-10 変更）。50 文字以内。
 */
class TraineeRequestNameTest extends TestCase
{
    private function errors(mixed $name): array
    {
        $request = TraineeRequest::create('/trainees', 'POST', ['name' => $name]);
        $request->setContainer($this->app);

        $validator = Validator::make($request->all(), $request->rules(), [], $request->attributes());
        $request->withValidator($validator);
        $validator->passes();

        return $validator->errors()->get('name');
    }

    public function test_名前は空でもエラーにならない(): void
    {
        $this->assertSame([], $this->errors(null));
    }

    public function test_名前は50文字までよい(): void
    {
        $this->assertSame([], $this->errors(str_repeat('あ', 50)));
    }

    public function test_名前が51文字ならエラー(): void
    {
        $this->assertSame(['名前は50文字以内で入力してください。'], $this->errors(str_repeat('あ', 51)));
    }
}
