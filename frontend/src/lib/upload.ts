// Upload through the file service (plan 01 F6): presign → PUT the bytes → complete →
// wait for the safety scan. Returns the file once it is clean.

import { api, apiData } from "./api";
import { ApiError } from "./problem";
import type { Presigned, StoredFile } from "./types";

export class UploadRejected extends Error {}
/** The scan didn't finish in time; the upload is kept and can be tried again. */
export class ScanPending extends Error {}

export async function uploadFile(file: File, purpose: string, onScanning?: () => void): Promise<StoredFile> {
  const { file: stored, upload } = await apiData<Presigned>("/files/presign", {
    method: "POST",
    body: { purpose, filename: file.name, size: file.size, mime: file.type || "application/octet-stream" },
  });

  let put: Response;
  try {
    put = await fetch(upload.url, { method: upload.method, headers: upload.headers, body: file, credentials: "same-origin" });
  } catch {
    throw new ApiError(0, null);
  }
  if (!put.ok) {
    const body = await put.json().catch(() => null);
    throw new ApiError(put.status, body);
  }

  let current = await apiData<StoredFile>(`/files/${stored.id}/complete`, { method: "POST", body: {} });
  onScanning?.();
  for (let i = 0; current.status === "pending" && i < 20; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    current = (await api<StoredFile>(`/files/${stored.id}`)).data;
  }
  if (current.status === "infected") throw new UploadRejected();
  if (current.status !== "clean") throw new ScanPending();
  return current;
}
