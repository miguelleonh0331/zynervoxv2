#!/usr/bin/env python3
"""Assert both known laboratory tones in every actual MixMonitor WAV."""
import array
import json
import math
import pathlib
import sys
import wave

folder = pathlib.Path(sys.argv[1])
results = json.loads((folder / 'results.json').read_text())
checked = 0
for mode in results:
    for call in mode['calls']:
        path = folder / ('call-' + str(call['call_id']) + '.wav')
        with wave.open(str(path), 'rb') as audio:
            assert audio.getnchannels() == 1 and audio.getsampwidth() == 2 and audio.getframerate() == 8000, path
            assert audio.getnframes() / 8000 >= 15, path
            audio.setpos(8 * 8000)
            samples = array.array('h', audio.readframes(8000))
        if sys.byteorder != 'little':
            samples.byteswap()
        def amplitude(frequency):
            real = sum(value * math.cos(2 * math.pi * frequency * i / 8000) for i, value in enumerate(samples))
            imag = sum(value * math.sin(2 * math.pi * frequency * i / 8000) for i, value in enumerate(samples))
            return 2 * math.hypot(real, imag) / len(samples)
        agent_frequency = 880 + (int(call['agent']) - 79970) * 40
        client_tone, agent_tone = amplitude(440), amplitude(agent_frequency)
        assert client_tone > 100 and agent_tone > 100, (path, client_tone, agent_tone)
        checked += 1
assert checked == 30, checked
print('PASS: thirty WAVs contain both client and assigned-agent tones during conversation.')
