document.addEventListener('DOMContentLoaded', function() {
    const transcripts = Array.from(document.querySelectorAll('.whisper-transcript[data-whisper-transcript="1"]'));
    if (!transcripts.length) {
        return;
    }

    transcripts.forEach(initTranscriptHighlighter);

    function initTranscriptHighlighter(transcript) {
        applyAdaptiveHighlightTheme(transcript);

        const segments = Array.from(transcript.querySelectorAll('.whisper-transcript-segment[data-whisper-start][data-whisper-end]'))
            .map(element => ({
                element,
                start: Number.parseFloat(element.dataset.whisperStart),
                end: Number.parseFloat(element.dataset.whisperEnd),
            }))
            .filter(segment => Number.isFinite(segment.start) && Number.isFinite(segment.end) && segment.end > segment.start)
            .sort((a, b) => a.start - b.start);

        if (!segments.length) {
            return;
        }

        const media = findTranscriptMedia(transcript);
        if (!media) {
            return;
        }

        let activeSegment = null;

        function setActiveSegment(nextSegment) {
            if (activeSegment === nextSegment) {
                return;
            }

            if (activeSegment) {
                activeSegment.element.classList.remove('whisper-transcript-segment-active');
                activeSegment.element.removeAttribute('aria-current');
            }

            activeSegment = nextSegment;

            if (activeSegment) {
                activeSegment.element.classList.add('whisper-transcript-segment-active');
                activeSegment.element.setAttribute('aria-current', 'true');
            }
        }

        function updateActiveSegment() {
            const currentTime = media.currentTime;
            const nextSegment = segments.find(segment => currentTime >= segment.start && currentTime <= segment.end) || null;
            setActiveSegment(nextSegment);
        }

        media.addEventListener('timeupdate', updateActiveSegment);
        media.addEventListener('seeked', updateActiveSegment);
        media.addEventListener('play', updateActiveSegment);
        media.addEventListener('ended', () => setActiveSegment(null));
    }

    function applyAdaptiveHighlightTheme(transcript) {
        const color = parseCssColor(getComputedStyle(transcript).color);
        if (!color) {
            return;
        }

        if (relativeLuminance(color) >= 0.55) {
            transcript.style.setProperty('--whisper-transcript-highlight-bg', 'rgba(8, 83, 103, 0.72)');
            transcript.style.setProperty('--whisper-transcript-highlight-color', '#ffffff');
            transcript.style.setProperty('--whisper-transcript-highlight-shadow', '0 0 0 1px rgba(186, 230, 253, 0.32) inset');
            return;
        }

        transcript.style.setProperty('--whisper-transcript-highlight-bg', 'rgba(219, 234, 254, 0.95)');
        transcript.style.setProperty('--whisper-transcript-highlight-color', '#111827');
        transcript.style.setProperty('--whisper-transcript-highlight-shadow', '0 0 0 1px rgba(37, 99, 235, 0.22) inset');
    }

    function findTranscriptMedia(transcript) {
        const mediaElements = Array.from(document.querySelectorAll('audio, video'));
        if (!mediaElements.length) {
            return null;
        }

        const audioUrl = normalizeUrl(transcript.dataset.whisperAudioUrl || '');
        if (audioUrl) {
            const matchingMedia = mediaElements.find(media => {
                const candidates = [
                    media.currentSrc,
                    media.src,
                    ...Array.from(media.querySelectorAll('source')).map(source => source.src),
                ].map(normalizeUrl).filter(Boolean);

                return candidates.some(candidate => candidate === audioUrl);
            });

            if (matchingMedia) {
                return matchingMedia;
            }
        }

        if (mediaElements.length === 1) {
            return mediaElements[0];
        }

        const precedingMedia = mediaElements
            .filter(media => Boolean(media.compareDocumentPosition(transcript) & Node.DOCUMENT_POSITION_FOLLOWING))
            .pop();

        return precedingMedia || mediaElements[0];
    }

    function normalizeUrl(url) {
        if (!url) {
            return '';
        }

        try {
            const parsed = new URL(url, window.location.href);
            parsed.hash = '';
            return parsed.href;
        } catch (error) {
            return '';
        }
    }

    function parseCssColor(color) {
        const match = String(color).match(/^rgba?\(([^)]+)\)$/i);
        if (!match) {
            return null;
        }

        const parts = match[1].split(',').map(part => Number.parseFloat(part.trim()));
        if (parts.length < 3 || parts.slice(0, 3).some(part => !Number.isFinite(part))) {
            return null;
        }

        return {
            red: clampColor(parts[0]),
            green: clampColor(parts[1]),
            blue: clampColor(parts[2]),
        };
    }

    function clampColor(value) {
        return Math.max(0, Math.min(255, value));
    }

    function relativeLuminance(color) {
        const channels = [color.red, color.green, color.blue].map(channel => {
            const normalized = channel / 255;
            return normalized <= 0.03928
                ? normalized / 12.92
                : Math.pow((normalized + 0.055) / 1.055, 2.4);
        });

        return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
    }
});
