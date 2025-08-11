import React from "react";

export default function AudioUpload() {
  return (
    <div className="rounded-xl border p-4">
      <h3 className="text-lg font-semibold mb-2">Upload audio</h3>
      <p className="text-sm text-gray-600 mb-3">
        We'll transcribe your file and keep only the text. No playback. Endpoints wired in Phase 5.
      </p>
      <input type="file" accept="audio/*" disabled className="cursor-not-allowed opacity-60" />
    </div>
  );
}
