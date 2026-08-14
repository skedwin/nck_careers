import api from './api';

/**
 * Download a protected API file using the Bearer token (window.open cannot).
 */
export async function downloadAuthorized(path: string, filename: string): Promise<void> {
  const response = await api.get(path, {
    responseType: 'blob',
    // Override default JSON Accept so binary downloads succeed
    headers: { Accept: '*/*' },
  });

  const contentType = (response.headers['content-type'] as string | undefined) ?? '';
  if (contentType.includes('application/json')) {
    const text = await (response.data as Blob).text();
    let message = 'Download failed.';
    try {
      const parsed = JSON.parse(text) as { message?: string };
      if (parsed.message) message = parsed.message;
    } catch {
      // keep fallback
    }
    throw new Error(message);
  }

  const blob = new Blob([response.data], {
    type: contentType || 'application/octet-stream',
  });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}

