document.addEventListener('DOMContentLoaded', function() {
    const transcripts = Array.from(document.querySelectorAll('.whisper-transcript[data-whisper-transcript="1"]'));
    if (!transcripts.length) {
        return;
    }

    transcripts.forEach(initTranscriptHighlighter);

    function initTranscriptHighlighter(transcript) {
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
});
