<?php
namespace App\Services;
use App\Models\AudioFile;

class FakeTranscriptionService implements TranscriptionService {
    public function transcribeAudioFile(AudioFile $audio): array {
        return [
            ['order'=>1,'start_ms'=>0,'end_ms'=>1200,'ai_text'=>'This is a fake chunk.'],
            ['order'=>2,'start_ms'=>1200,'end_ms'=>2400,'ai_text'=>'Used for pipeline smoke tests.'],
        ];
    }
}
