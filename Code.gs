const SPREADSHEET_ID = "1gXD1eMJeMRnjod9qRu80bfOq81r5MQ5KAep3ljgrM6o";
const APP_BUILD = "MATRIKULASI-20261117-R5-NIP";
const PHP_RAPOR_API_KEY = "1f70094816f9aba94170f469b521e36213835984e7361bd561a95f1c6180b80e";
const KODE_MAPEL = Array.from({length: 13}, (_, i) => String(101 + i));
const ASUH_BARIS_AWAL = 3;
const ASUH_BARIS_AKHIR = 109;

function doGet(e) {
  const action = teks_(e && e.parameter && e.parameter.action).toLowerCase();

  // --- API JSON UNTUK APLIKASI PHP CETAK RAPOR ---
  // Endpoint laporan hanya menerima permintaan dari server PHP yang memiliki API key.
  if (["list", "detail", "auth_wali_kelas"].includes(action)) {
    const apiKey = teks_(e && e.parameter && e.parameter.api_key);
    if (!apiKey || apiKey !== PHP_RAPOR_API_KEY) {
      return jsonOut_({success: false, error: "Akses API ditolak."});
    }
  }

  if (action === "auth_wali_kelas") {
    const nip = teks_(e && e.parameter && e.parameter.nip);
    return jsonOut_(validasiLoginWaliKelasByNip_(nip));
  }
  if (action === "list") {
    return jsonOut_(getDaftarSiswaRapor_());
  }
  if (action === "detail") {
    const baris = Number(e && e.parameter && e.parameter.baris);
    return jsonOut_(getDetailRaporSiswa_(baris));
  }

  // --- HALAMAN WEB APP INPUT NILAI (BAWAAN) ---
  const titles = {
    login: "Sistem Matrikulasi",
    guru: "Dashboard Guru | Sistem Matrikulasi",
    "wali-asuh": "Dashboard Wali Asuh | Sistem Matrikulasi",
    "wali-kelas": "Dashboard Wali Kelas | Sistem Matrikulasi"
  };
  const page = teks_(e && e.parameter && e.parameter.page || "login").toLowerCase();
  return HtmlService.createHtmlOutputFromFile("Index")
    .setTitle(titles[page] || titles.login)
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

function jsonOut_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

function getDaftarSiswaRapor_() {
  try {
    const ss = ss_();
    const s = sheet_(ss, "101");
    const last = Math.min(s.getLastRow(), ASUH_BARIS_AKHIR);
    if (last < ASUH_BARIS_AWAL) return [];

    const rows = s.getRange(ASUH_BARIS_AWAL, 1, last - ASUH_BARIS_AWAL + 1, 4).getDisplayValues();
    const list = [];
    rows.forEach((r, i) => {
      if (validNamaSiswa_(r[1])) {
        list.push({
          baris: i + ASUH_BARIS_AWAL,
          nama: teks_(r[1]),
          kelas: teks_(r[2]),
          wali_kelas: teks_(r[3])
        });
      }
    });
    return list;
  } catch (e) {
    return { error: String(e && e.message || e) };
  }
}

function getDetailRaporSiswa_(baris) {
  try {
    if (!Number.isInteger(baris) || baris < ASUH_BARIS_AWAL || baris > ASUH_BARIS_AKHIR) {
      return { error: "Nomor baris siswa tidak valid." };
    }

    const ss = ss_();
    const s101 = sheet_(ss, "101");
    const idRow = s101.getRange(baris, 1, 1, 4).getDisplayValues()[0];
    if (!validNamaSiswa_(idRow[1])) {
      return { error: "Data identitas siswa pada baris " + baris + " kosong." };
    }

    const nipWaliKelas = nipPengguna_(ss, idRow[3], "WALI_KELAS");
    const identitas = {
      nama: teks_(idRow[1]),
      kelas: teks_(idRow[2]),
      wali_kelas: teks_(idRow[3]),
      nip_wali_kelas: nipWaliKelas,
      wali_kelas_nip: nipWaliKelas
    };

    // Ambil nama resmi mata pelajaran dari sheet 'verif'
    const namaMapelMap = {};
    dataVerif_(ss).forEach(r => {
      const k = teks_(r[0]);
      if (KODE_MAPEL.includes(k) && teks_(r[1]) && !namaMapelMap[k]) {
        namaMapelMap[k] = teks_(r[1]);
      }
    });

    // Ambil nilai & deskripsi dari seluruh sheet 101 - 113 sesuai urutan kolom M, N, O, P
    const mapelList = [];
    KODE_MAPEL.forEach(kode => {
      const sh = ss.getSheetByName(kode);
      if (!sh || sh.getLastRow() < baris) return;

      const r = sh.getRange(baris, 1, 1, 17).getDisplayValues()[0];
      // Pastikan baris di sheet mapel merujuk siswa yang sama; jika tidak, cari berdasarkan nama+kelas
      let rowData = r;
      if (normal_(r[1]) !== normal_(identitas.nama) || normal_(r[2]) !== normal_(identitas.kelas)) {
        const allRows = sh.getRange(ASUH_BARIS_AWAL, 1, Math.max(0, sh.getLastRow() - ASUH_BARIS_AWAL + 1), 17).getDisplayValues();
        const found = allRows.find(x => normal_(x[1]) === normal_(identitas.nama) && normal_(x[2]) === normal_(identitas.kelas));
        if (!found) return;
        rowData = found;
      }

      mapelList.push({
        kode: kode,
        mapel: namaMapelMap[kode] || ("Mapel " + kode),
        rata_rata: teks_(rowData[12]) || "-",      // Kolom M (indeks 12)
        capaian_awal: teks_(rowData[13]) || "-",   // Kolom N (indeks 13)
        capaian_akhir: teks_(rowData[14]) || "-",  // Kolom O (indeks 14)
        ketuntasan: teks_(rowData[15]) || "-",     // Kolom P (indeks 15)
        catatan: teks_(rowData[16]) || ""          // Kolom Q (indeks 16)
      });
    });

    // Ambil data Wali Asuh dengan pencocokan Nama + Kelas agar akurat meski nomor baris berbeda
    let waliAsuh = { nama: "-", aspek1: "-", aspek2: "-", aspek3: "-", aspek4: "-" };
    const sAsuh = ss.getSheetByName("wali_asuh");
    if (sAsuh && sAsuh.getLastRow() >= ASUH_BARIS_AWAL) {
      const asuhRows = sAsuh.getRange(ASUH_BARIS_AWAL, 1, ASUH_BARIS_AKHIR - ASUH_BARIS_AWAL + 1, 10).getDisplayValues();
      const match = asuhRows.find(r => normal_(r[2]) === normal_(identitas.nama) && normal_(r[5]) === normal_(identitas.kelas));
      if (match) {
        waliAsuh = {
          nama: teks_(match[3]) || "-",
          aspek1: teks_(match[6]) || "-",
          aspek2: teks_(match[7]) || "-",
          aspek3: teks_(match[8]) || "-",
          aspek4: teks_(match[9]) || "-"
        };
      }
    }

    // Ambil catatan Wali Kelas dari sheet "walas".
    // Fungsi ini mengembalikan string kosong jika sheet belum tersedia.
    const catatanWaliKelas = getCatatanWaliKelasForRapor(
      identitasSiswaKey_(identitas.nama, identitas.kelas)
    );

    return {
      identitas: identitas,
      wali_asuh: waliAsuh,
      catatan_wali_kelas: catatanWaliKelas,
      mapel: mapelList
    };
  } catch (e) {
    return { error: String(e && e.message || e) };
  }
}

function getVersiAplikasi() {
  return {success: true, build: APP_BUILD, scriptId: ScriptApp.getScriptId()};
}
function ss_() { return SpreadsheetApp.openById(SPREADSHEET_ID); }
function sheet_(ss, nama) {
  const s = ss.getSheetByName(String(nama));
  if (!s) throw new Error("Sheet '" + nama + "' tidak ditemukan.");
  return s;
}
function teks_(v) { return String(v ?? "").trim(); }
function normal_(v) {
  return teks_(v).normalize("NFC").replace(/\s+/g, " ").toLowerCase();
}

/**
 * NIP diperlakukan sebagai teks, bukan angka.
 * Pemisah/spasi diabaikan agar format tampilan tidak mengganggu pencocokan.
 * Nilai placeholder seperti n/a dan "Tidak Ditemukan" tidak pernah dianggap NIP.
 */
function normalisasiNip_(v) {
  return teks_(v).replace(/[^0-9]/g, "");
}
function nipValid_(v) {
  const n = normalisasiNip_(v);
  return n.length >= 8 && n.length <= 30;
}
function error_(e) { return {success: false, message: String(e && e.message || e), build: APP_BUILD}; }
function dataVerif_(ss) {
  const s = sheet_(ss, "verif"), last = s.getLastRow();
  return last >= 2 ? s.getRange(2, 1, last - 1, 5).getDisplayValues() : [];
}
function cocokRole_(row, input) {
  const role = teks_(input).toUpperCase();
  const kode = teks_(row[0]).toUpperCase();
  const materi = normal_(row[1]);
  const jenis = normal_(row[3]);

  if (role === "GURU") return jenis === "guru" && !materi.includes("wali kelas");

  // Di sheet verif, kolom B menandai penugasan "Wali Kelas X-n".
  // Kolom D pada data saat ini dapat tetap berisi "Guru", sehingga tidak
  // dipakai sebagai penentu penugasan wali kelas. NIP kolom E wajib valid.
  if (role === "WALI_KELAS") {
    return materi.includes("wali kelas") && nipValid_(row[4]);
  }

  if (role === "WALI_ASUH") return jenis.includes("asuh") || kode === "WA";
  return false;
}
function penggunaValid_(ss, nama, role) {
  return !!teks_(nama) && dataVerif_(ss).some(r => normal_(r[2]) === normal_(nama) && cocokRole_(r, role));
}

/** Mengambil NIP pengguna dari kolom E sheet verif. */
function nipPengguna_(ss, nama, role) {
  if (!teks_(nama)) return "";
  const row = dataVerif_(ss).find(function (r) {
    return normal_(r[2]) === normal_(nama)
      && cocokRole_(r, role)
      && nipValid_(r[4]);
  });
  return row ? teks_(row[4]) : "";
}

/**
 * Validasi login aplikasi PHP menggunakan NIP kolom E sheet verif.
 * Hanya mengembalikan nama resmi dan kelas untuk baris ber-role Wali Kelas.
 */
function validasiLoginWaliKelasByNip_(nip) {
  try {
    const inputNip = normalisasiNip_(nip);

    if (!inputNip) {
      return {success: false, message: "NIP wajib diisi.", build: APP_BUILD};
    }
    if (inputNip.length < 8 || inputNip.length > 30) {
      return {success: false, message: "Format NIP tidak valid. Periksa kembali NIP pada kolom E.", build: APP_BUILD};
    }

    const allRows = dataVerif_(ss_());
    const waliRows = allRows.filter(function (r) {
      return normal_(r[1]).includes("wali kelas");
    });

    const rows = waliRows.filter(function (r) {
      return nipValid_(r[4]) && normalisasiNip_(r[4]) === inputNip;
    });

    if (!rows.length) {
      const hasSameNipOnNonWaliAssignment = allRows.some(function (r) {
        return nipValid_(r[4]) && normalisasiNip_(r[4]) === inputNip
          && !normal_(r[1]).includes("wali kelas");
      });

      if (hasSameNipOnNonWaliAssignment) {
        return {
          success: false,
          message: "NIP ditemukan pada sheet verif, tetapi baris tersebut bukan penugasan Wali Kelas pada kolom B.",
          build: APP_BUILD
        };
      }

      return {
        success: false,
        message: "NIP tidak ditemukan pada kolom E untuk penugasan Wali Kelas di kolom B. Pastikan NIP tersimpan sebagai teks dan sama persis.",
        build: APP_BUILD
      };
    }

    const names = [];
    rows.forEach(function (r) {
      const name = teks_(r[2]);
      if (name && !names.some(function (existing) {
        return normal_(existing) === normal_(name);
      })) {
        names.push(name);
      }
    });

    if (names.length !== 1) {
      return {
        success: false,
        message: "NIP terhubung ke lebih dari satu nama Wali Kelas. Periksa data duplikat pada sheet verif.",
        build: APP_BUILD
      };
    }

    const officialName = names[0];
    const classes = [];
    rows.forEach(function (r) {
      const className = teks_(r[1]).replace(/Wali Kelas/ig, "").trim();
      if (className && !classes.some(function (existing) {
        return normal_(existing) === normal_(className);
      })) {
        classes.push(className);
      }
    });

    if (!classes.length) {
      return {
        success: false,
        message: "NIP valid, tetapi nama kelas tidak dapat dibaca dari kolom B.",
        build: APP_BUILD
      };
    }

    return {
      success: true,
      role: "WALI_KELAS",
      namaWaliKelas: officialName,
      nipWaliKelas: teks_(rows[0][4]),
      kelasList: classes.sort(),
      build: APP_BUILD
    };
  } catch (e) {
    return {
      success: false,
      message: String(e && e.message || e),
      build: APP_BUILD
    };
  }
}
function cekPayload_(p) {
  if (!Array.isArray(p) || !p.length) throw new Error("Tidak ada data untuk disimpan.");
}
function cekBaris_(v, min, max, used) {
  const r = Number(v);
  if (!Number.isInteger(r) || r < min || r > max) throw new Error("Baris siswa tidak valid: " + v);
  if (used.has(r)) throw new Error("Data siswa ganda pada baris " + r + ".");
  used.add(r);
  return r;
}
function validNamaSiswa_(v) { return !!normal_(v) && !["nama", "nama siswa", "nama lengkap siswa"].includes(normal_(v)); }
function identitasSiswaKey_(nama, kelas) { return JSON.stringify([normal_(nama), normal_(kelas)]); }
function kodeMapelValid_(v) {
  const k = teks_(v);
  if (!KODE_MAPEL.includes(k)) throw new Error("Kode mapel tidak valid: " + k);
  return k;
}
function nilaiAngka_(v, label) {
  const t = teks_(v);
  if (t === "") return "";
  const n = Number(t.replace(",", "."));
  if (!Number.isFinite(n) || n < 0 || n > 100) throw new Error(label + " harus berupa angka antara 0 sampai 100.");
  return n;
}
function rataRataTugas_(values) {
  const a = values.filter(v => v !== "");
  return a.length ? Number((a.reduce((s, v) => s + v, 0) / a.length).toFixed(2)) : "";
}
function hitungNilaiAkhir_(t5, asesmen, kehadiran, sikap) {
  const nilai = [t5, asesmen, kehadiran, sikap];
  if (nilai.some(v => v === "")) return "";
  const hasil = (nilai[0] * 0.50) + (nilai[1] * 0.30) + (nilai[2] * 0.10) + (nilai[3] * 0.10);
  return Number(hasil.toFixed(2));
}
function statusKetuntasan_(nilaiAkhir) {
  if (nilaiAkhir === "" || nilaiAkhir === null || nilaiAkhir === undefined) return "";
  return Number(nilaiAkhir) >= 70 ? "Tuntas" : "Tidak Tuntas";
}
function statusPengisianAsuh_(values) {
  const n = values.filter(v => teks_(v) !== "").length;
  return n === 4 ? "Lengkap" : n ? "Sebagian" : "Belum diisi";
}
function getDaftarKelas(s) {
  const last = s.getLastRow();
  if (last < 3) return [];
  return [...new Set(s.getRange(3, 3, last - 2, 1).getDisplayValues().map(r => teks_(r[0])).filter(Boolean))].sort();
}
function searchUserByRole(role, keyword) {
  if (teks_(keyword).length < 3) return [];
  const seen = new Set(), list = [];
  const q = normal_(keyword);
  dataVerif_(ss_()).forEach(r => {
    const nama = teks_(r[2]);
    const nip = teks_(r[4]);
    const key = JSON.stringify([normal_(nama), normal_(nip)]);
    const cocokPencarian = normal_(nama).includes(q) || normal_(nip).includes(q);
    if (cocokRole_(r, role) && nama && cocokPencarian && !seen.has(key)) {
      seen.add(key);
      list.push({nama: nama, nip: nip});
    }
  });
  return list;
}
function getMapelByGuru(nama) {
  try {
    const seen = new Set(), list = [];
    dataVerif_(ss_()).forEach(r => {
      const kode = teks_(r[0]);
      if (normal_(r[2]) === normal_(nama) && cocokRole_(r, "GURU") && KODE_MAPEL.includes(kode) && !seen.has(kode)) {
        seen.add(kode); list.push({kode: kode, label: kode + " — " + teks_(r[1])});
      }
    });
    return {success: true, mapelList: list, namaGuru: teks_(nama), nipGuru: nipPengguna_(ss_(), nama, "GURU"), build: APP_BUILD};
  } catch (e) { return error_(e); }
}
function resolveNamaGuru_(ss, kode, nama) {
  if (!teks_(nama)) throw new Error("Nama guru belum dikirim. Login kembali dan periksa versi HTML.");
  const row = dataVerif_(ss).find(r => normal_(r[2]) === normal_(nama) && teks_(r[0]) === kode && cocokRole_(r, "GURU"));
  if (!row) throw new Error("Nama guru tidak sesuai pengampu mapel " + kode + " pada sheet verif.");
  return teks_(row[2]);
}
function getKelasByMapel(kode) {
  try {
    kode = kodeMapelValid_(kode);
    return {success: true, kodeMapel: kode, daftarKelas: getDaftarKelas(sheet_(ss_(), kode)), build: APP_BUILD};
  } catch (e) { return error_(e); }
}
function getDataSiswa(kode, kelas) {
  try {
    kode = kodeMapelValid_(kode); kelas = teks_(kelas);
    if (!kelas) throw new Error("Kelas belum dipilih.");
    const s = sheet_(ss_(), kode), last = s.getLastRow(), data = [];
    if (last >= 3) s.getRange(3, 1, last - 2, 17).getDisplayValues().forEach((r, i) => {
      if (!validNamaSiswa_(r[1]) || (kelas.toUpperCase() !== "SEMUA" && normal_(r[2]) !== normal_(kelas))) return;
      data.push({rowIndex: i + 3, no: r[0], nama: r[1], kelas: r[2], waliKelas: r[3], tugas1: r[4], tugas2: r[5], tugas3: r[6], tugas4: r[7], tugas5: r[8], assMatriks: r[9], kehadiran: r[10], sikap: r[11], rataRata: r[12], rataRataAkhir: r[12], capaianAwal: r[13], capaianAkhir: r[14], ketuntasan: r[15], catatan: r[16]});
    });
    return {success: true, data: data, build: APP_BUILD};
  } catch (e) { return error_(e); }
}
function cekIdentitas_(kode, baris, namaSheet, kelasSheet, item) {
  if (validNamaSiswa_(namaSheet) && normal_(namaSheet) === normal_(item.nama) && normal_(kelasSheet) === normal_(item.kelas)) return;
  throw new Error(
    "[IDENTITAS-R4] Versi " + APP_BUILD + "\n" +
    "Sheet: " + kode + ", baris: " + baris + "\n\n" +
    "SPREADSHEET:\nNama: " + JSON.stringify(String(namaSheet ?? "")) + "\nKelas: " + JSON.stringify(String(kelasSheet ?? "")) +
    "\n\nBROWSER:\nNama: " + JSON.stringify(String(item.nama ?? "")) + "\nKelas: " + JSON.stringify(String(item.kelas ?? "")) +
    "\n\nPenyimpanan dibatalkan. Periksa struktur kolom dan muat ulang data siswa."
  );
}
function simpanNilai(kode, payload, namaGuru, clientBuild) {
  const lock = LockService.getScriptLock();
  let locked = false;
  try {
    if (clientBuild !== APP_BUILD) {
      throw new Error("[VERSI-R4] HTML dan backend tidak cocok. HTML: " + String(clientBuild || "tidak dikirim") + "; backend: " + APP_BUILD + ". Perbarui deployment dan buka ulang aplikasi.");
    }

    cekPayload_(payload);
    kode = kodeMapelValid_(kode);
    locked = lock.tryLock(20000);
    if (!locked) throw new Error("Sistem sedang menyimpan data lain. Coba kembali.");

    const ss = ss_();
    resolveNamaGuru_(ss, kode, namaGuru);
    const s = sheet_(ss, kode);
    const last = s.getLastRow();
    if (last < 3) throw new Error("Data siswa belum tersedia.");

    const ids = s.getRange(3, 2, last - 2, 2).getDisplayValues();
    const used = new Set();

    const valid = payload.map(item => {
      if (!item || typeof item !== "object" || Array.isArray(item)) {
        throw new Error("Format data siswa tidak valid.");
      }

      const r = cekBaris_(item.rowIndex, 3, last, used);
      const id = ids[r - 3];
      cekIdentitas_(kode, r, id[0], id[1], item);

      const label = "Nilai " + id[0];
      const tugas = [1, 2, 3, 4].map(n => nilaiAngka_(item["tugas" + n], label + " T" + n));
      const t5 = rataRataTugas_(tugas);
      const asesmen = nilaiAngka_(item.assMatriks, label + " Ass Matriks");
      const kehadiran = nilaiAngka_(item.kehadiran, label + " Kehadiran");
      const sikap = nilaiAngka_(item.sikap, label + " Sikap");

      const rataRataAkhir = hitungNilaiAkhir_(t5, asesmen, kehadiran, sikap);
      const ketuntasan = statusKetuntasan_(rataRataAkhir);

      if (Object.prototype.hasOwnProperty.call(item, "rataRataAkhir")) {
        const dariBrowser = nilaiAngka_(item.rataRataAkhir, label + " Rata-Rata Akhir");
        if (dariBrowser !== rataRataAkhir) {
          throw new Error("Perhitungan Rata-Rata Akhir tidak cocok untuk " + id[0] + ". Muat ulang halaman dan coba simpan kembali.");
        }
      }
      if (Object.prototype.hasOwnProperty.call(item, "ketuntasan") && teks_(item.ketuntasan) !== ketuntasan) {
        throw new Error("Perhitungan Ketuntasan tidak cocok untuk " + id[0] + ". Muat ulang halaman dan coba simpan kembali.");
      }

      const capaianAwal = String(item.capaianAwal ?? "");
      const capaianAkhir = String(item.capaianAkhir ?? "");
      const catatan = String(item.catatan ?? "");

      const values = [
        tugas[0], tugas[1], tugas[2], tugas[3], t5,
        asesmen, kehadiran, sikap, rataRataAkhir,
        capaianAwal, capaianAkhir, ketuntasan, catatan
      ];

      return {r: r, values: values, rataRataAkhir: rataRataAkhir, ketuntasan: ketuntasan};
    });

    valid.forEach(v => {
      s.getRange(v.r, 5, 1, 13).setValues([v.values]);
    });

    SpreadsheetApp.flush();
    return {
      success: true,
      message: "Nilai " + valid.length + " siswa berhasil disimpan, termasuk Rata-Rata Akhir (kolom M) dan Ketuntasan (kolom P).",
      build: APP_BUILD,
      saved: {rataRataAkhir: true, ketuntasan: true}
    };
  } catch (e) {
    return error_(e);
  } finally {
    if (locked) lock.releaseLock();
  }
}
function getAllSiswaAsuh() {
  try {
    const list = [];
    sheet_(ss_(), "wali_asuh").getRange(ASUH_BARIS_AWAL, 1, ASUH_BARIS_AKHIR - ASUH_BARIS_AWAL + 1, 10).getDisplayValues().forEach((r, i) => {
      if (validNamaSiswa_(r[2])) list.push({rowIndex: i + ASUH_BARIS_AWAL, nama: teks_(r[2]), namaWaliAsuh: teks_(r[3]), kelas: teks_(r[5]), n1: r[6], n2: r[7], n3: r[8], n4: r[9]});
    });
    return {success: true, listSiswa: list, build: APP_BUILD};
  } catch (e) { return error_(e); }
}
function simpanNilaiWaliAsuh(payload, nama) {
  const lock = LockService.getScriptLock(); let locked = false;
  try {
    cekPayload_(payload);
    if (!teks_(nama)) throw new Error("Nama wali asuh belum diterima. Silakan login kembali.");
    locked = lock.tryLock(20000);
    if (!locked) throw new Error("Sistem sedang menyimpan data lain. Coba kembali.");
    const ss = ss_(), u = dataVerif_(ss).find(r => normal_(r[2]) === normal_(nama) && cocokRole_(r, "WALI_ASUH"));
    if (!u) throw new Error("Nama wali asuh tidak terdaftar pada sheet verif.");
    const resmi = teks_(u[2]), s = sheet_(ss, "wali_asuh"), ids = s.getRange(ASUH_BARIS_AWAL, 3, ASUH_BARIS_AKHIR - ASUH_BARIS_AWAL + 1, 4).getDisplayValues(), used = new Set();
    const valid = payload.map(item => {
      if (!item || typeof item !== "object" || Array.isArray(item)) throw new Error("Format data Wali Asuh tidak valid.");
      const r = cekBaris_(item.rowIndex, ASUH_BARIS_AWAL, ASUH_BARIS_AKHIR, used), id = ids[r - ASUH_BARIS_AWAL];
      cekIdentitas_("wali_asuh (C/F)", r, id[0], id[3], item);
      return {r: r, nilai: [1, 2, 3, 4].map(n => String(item["n" + n] ?? ""))};
    });
    valid.forEach(v => { s.getRange(v.r, 7, 1, 4).setValues([v.nilai]); s.getRange(v.r, 4).setValue(resmi); });
    SpreadsheetApp.flush();
    return {success: true, namaWaliAsuh: resmi, message: "Nilai/deskripsi " + valid.length + " siswa berhasil disimpan. Nama " + resmi + " dicatat di kolom D.", build: APP_BUILD};
  } catch (e) { return error_(e); } finally { if (locked) lock.releaseLock(); }
}
function kelasByWali_(ss, nama) {
  const seen = new Set(), list = [];
  dataVerif_(ss).forEach(r => {
    if (normal_(r[2]) !== normal_(nama) || !cocokRole_(r, "WALI_KELAS") || !nipValid_(r[4])) return;
    const k = teks_(r[1]).replace(/Wali Kelas/i, "").trim();
    if (k && !seen.has(normal_(k))) { seen.add(normal_(k)); list.push(k); }
  });
  return list.sort();
}
function validasiKelasWali_(ss, kelas, nama) {
  if (!teks_(nama)) throw new Error("Nama Wali Kelas belum diterima. Silakan login kembali.");
  if (!teks_(kelas)) throw new Error("Kelas belum dipilih.");
  const resmi = kelasByWali_(ss, nama).find(k => normal_(k) === normal_(kelas));
  if (!resmi) throw new Error("Kelas tidak sesuai dengan wali kelas yang dipilih.");
  return resmi;
}
function rekapMapelKelas_(ss, kelas) {
  const map = new Map();
  KODE_MAPEL.forEach(kode => {
    const s = ss.getSheetByName(kode);
    if (!s || s.getLastRow() < 3) return;
    s.getRange(3, 1, s.getLastRow() - 2, 17).getDisplayValues().forEach(r => {
      if (!validNamaSiswa_(r[1]) || normal_(r[2]) !== normal_(kelas)) return;
      const key = identitasSiswaKey_(r[1], r[2]);
      if (!map.has(key)) map.set(key, {nama: teks_(r[1]), kelas: teks_(r[2]), waliKelas: teks_(r[3]), mapelData: {}});
      map.get(key).mapelData[kode] = {capaianAwal: r[13], capaianAkhir: r[14], ketuntasan: r[15], catatan: r[16]};
    });
  });
  return Array.from(map.values());
}
function getKelasByWali(nama) {
  try { const ss = ss_(); return {success: true, kelasList: kelasByWali_(ss, nama), namaWaliKelas: teks_(nama), nipWaliKelas: nipPengguna_(ss, nama, "WALI_KELAS"), build: APP_BUILD}; } catch (e) { return error_(e); }
}
function getRekapWaliKelas(kelas, nama) {
  try { const ss = ss_(); kelas = validasiKelasWali_(ss, kelas, nama); return {success: true, data: rekapMapelKelas_(ss, kelas), build: APP_BUILD}; } catch (e) { return error_(e); }
}
function getPenilaianWaliAsuhUntukWaliKelas(kelas, nama) {
  try {
    const ss = ss_(); kelas = validasiKelasWali_(ss, kelas, nama);
    const daftar = new Map(), asuh = new Map();
    rekapMapelKelas_(ss, kelas).forEach(s => daftar.set(identitasSiswaKey_(s.nama, s.kelas), s));
    sheet_(ss, "wali_asuh").getRange(ASUH_BARIS_AWAL, 1, ASUH_BARIS_AKHIR - ASUH_BARIS_AWAL + 1, 10).getDisplayValues().forEach((r, i) => {
      if (!validNamaSiswa_(r[2]) || normal_(r[5]) !== normal_(kelas)) return;
      const key = identitasSiswaKey_(r[2], r[5]), baris = i + ASUH_BARIS_AWAL;
      if (asuh.has(key)) throw new Error("Data siswa ganda pada sheet wali_asuh: " + r[2] + " — " + r[5] + ", baris " + asuh.get(key).rowIndex + " dan " + baris + ".");
      asuh.set(key, {rowIndex: baris, namaWaliAsuh: teks_(r[3]), nilai: r.slice(6, 10)});
      if (!daftar.has(key)) daftar.set(key, {nama: teks_(r[2]), kelas: teks_(r[5]), waliKelas: teks_(nama)});
    });
    const data = [];
    daftar.forEach((s, key) => {
      const a = asuh.get(key), v = a ? a.nilai : ["", "", "", ""];
      data.push({nama: s.nama, kelas: s.kelas, waliKelas: s.waliKelas || teks_(nama), namaWaliAsuh: a ? a.namaWaliAsuh : "", n1: v[0], n2: v[1], n3: v[2], n4: v[3], statusPengisian: statusPengisianAsuh_(v), diperbarui: "", tersediaDiSheetAsuh: !!a});
    });
    data.sort((a, b) => a.nama.localeCompare(b.nama, "id", {sensitivity: "base", numeric: true}));
    const ringkasan = {total: data.length, lengkap: 0, sebagian: 0, belumDiisi: 0, belumAdaDiSheetAsuh: 0};
    data.forEach(s => {
      if (s.statusPengisian === "Lengkap") ringkasan.lengkap++;
      else if (s.statusPengisian === "Sebagian") ringkasan.sebagian++;
      else ringkasan.belumDiisi++;
      if (!s.tersediaDiSheetAsuh) ringkasan.belumAdaDiSheetAsuh++;
    });
    return {success: true, kelas: kelas, namaWaliKelas: teks_(nama), nipWaliKelas: nipPengguna_(ss_(), nama, "WALI_KELAS"), data: data, ringkasan: ringkasan, build: APP_BUILD};
  } catch (e) { return error_(e); }
}

/* =========================================================
 * CATATAN WALI KELAS — sheet "walas"
 * B=Wali Kelas (otomatis), C=NIP (manual), E=Nama Siswa,
 * G=Kelas, J=Catatan Wali Kelas. Data mulai baris 2 s.d. 1000.
 *
 * Terintegrasi dengan helper utama:
 * ss_(), identitasSiswaKey_(), dan
 * getPenilaianWaliAsuhUntukWaliKelas().
 * ========================================================= */
const WALAS_SHEET = "walas";
const WALAS_BARIS_AWAL = 2;
const WALAS_BARIS_AKHIR = 1000;
const WALAS_MAX_CATATAN = 1000;

function sheetWalas_() {
  const sh = ss_().getSheetByName(WALAS_SHEET);
  if (!sh) {
    throw new Error('Sheet "walas" tidak ditemukan. Jalankan setupSheetWalas() terlebih dahulu.');
  }
  return sh;
}

/**
 * Pemeriksaan ringan sheet walas.
 * Catatan berada di J2:J1000; fungsi ini tidak mengubah isi data.
 * Kolom C dipertahankan sebagai teks agar NIP dengan nol di depan tidak hilang.
 */
function setupSheetWalas() {
  const sh = sheetWalas_();
  const n = WALAS_BARIS_AKHIR - WALAS_BARIS_AWAL + 1;
  sh.getRange(WALAS_BARIS_AWAL, 3, n, 1).setNumberFormat("@");
  sh.getRange(WALAS_BARIS_AWAL, 10, n, 1)
    .setWrap(true)
    .setVerticalAlignment("top");
  sh.setColumnWidth(10, 420);
  return "Sheet walas siap. Data J2:J1000 dipertahankan.";
}

/** Map<identitasSiswaKey, {rowIndex, nama, kelas, waliKelas, nip, catatan}> */
function petaWalas_() {
  const sh = sheetWalas_();
  const n = WALAS_BARIS_AKHIR - WALAS_BARIS_AWAL + 1;
  const vals = sh.getRange(WALAS_BARIS_AWAL, 1, n, 10).getValues();
  const peta = new Map();

  vals.forEach(function (r, i) {
    const nama = String(r[4] || "").trim();
    const kelas = String(r[6] || "").trim();
    if (!nama || !kelas) return;

    const key = identitasSiswaKey_(nama, kelas);
    if (peta.has(key)) return; // Ambil baris pertama bila terdapat duplikat.

    peta.set(key, {
      rowIndex: WALAS_BARIS_AWAL + i,
      nama: nama,
      kelas: kelas,
      waliKelas: String(r[1] || "").trim(),
      nip: String(r[2] || "").trim(),
      catatan: String(r[9] || "")
    });
  });

  return peta;
}

/** Mengambil daftar siswa dan catatan untuk dashboard Wali Kelas. */
function getCatatanWaliKelas(kelas, namaWali) {
  try {
    // Fungsi utama sekaligus memvalidasi hubungan kelas dan Wali Kelas.
    const res = getPenilaianWaliAsuhUntukWaliKelas(kelas, namaWali);
    if (!res || !res.success) {
      return res || { success: false, message: "Gagal memuat daftar siswa." };
    }

    const peta = petaWalas_();
    let terisi = 0;
    let tidakDiSheet = 0;

    const data = (res.data || []).map(function (s) {
      const w = peta.get(identitasSiswaKey_(s.nama, s.kelas));
      if (!w) {
        tidakDiSheet++;
      } else if (w.catatan.trim()) {
        terisi++;
      }

      return {
        nama: s.nama,
        kelas: s.kelas,
        waliKelas: s.waliKelas || namaWali,
        rowIndex: w ? w.rowIndex : 0,
        nip: w ? w.nip : "",
        catatan: w ? w.catatan : "",
        tersediaDiSheetWalas: !!w
      };
    });

    return {
      success: true,
      data: data,
      ringkasan: {
        total: data.length,
        terisi: terisi,
        kosong: data.length - terisi - tidakDiSheet,
        tidakDiSheet: tidakDiSheet
      }
    };
  } catch (e) {
    return { success: false, message: e.message || String(e) };
  }
}

/**
 * payload = {
 *   kelas,
 *   namaWali,
 *   items: [{ rowIndex, nama, kelas, catatan }]
 * }
 */
function simpanCatatanWaliKelas(payload) {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(15000)) {
    return { success: false, message: "Server sibuk, coba lagi sebentar." };
  }

  try {
    if (!payload || !Array.isArray(payload.items) || !payload.items.length) {
      return { success: false, message: "Tidak ada catatan untuk disimpan." };
    }

    // Daftar siswa ini hanya berisi siswa yang menjadi tanggung jawab Wali Kelas.
    const izin = getPenilaianWaliAsuhUntukWaliKelas(payload.kelas, payload.namaWali);
    if (!izin || !izin.success) {
      return izin || { success: false, message: "Validasi Wali Kelas gagal." };
    }

    const kunciIzin = new Set(
      (izin.data || []).map(function (s) {
        return identitasSiswaKey_(s.nama, s.kelas);
      })
    );

    const sh = sheetWalas_();
    const n = WALAS_BARIS_AKHIR - WALAS_BARIS_AWAL + 1;
    const peta = petaWalas_();
    const idVals = sh.getRange(WALAS_BARIS_AWAL, 1, n, 7).getValues(); // A:G
    const colB = sh.getRange(WALAS_BARIS_AWAL, 2, n, 1).getValues();
    const colJ = sh.getRange(WALAS_BARIS_AWAL, 10, n, 1).getValues();

    const gagal = [];
    let disimpan = 0;
    const namaWaliTersimpan = String(izin.namaWaliKelas || payload.namaWali || "").trim();

    payload.items.forEach(function (item) {
      if (!item || typeof item !== "object" || Array.isArray(item)) {
        gagal.push("Format data siswa tidak valid.");
        return;
      }

      const nama = String(item.nama || "").trim();
      const kelas = String(item.kelas || "").trim();
      if (!nama || !kelas) {
        gagal.push((nama || "Siswa") + ": nama atau kelas kosong");
        return;
      }

      const key = identitasSiswaKey_(nama, kelas);
      if (!kunciIzin.has(key)) {
        gagal.push(nama + ": bukan siswa kelas Anda");
        return;
      }

      let catatan = String(item.catatan == null ? "" : item.catatan).replace(/\r\n/g, "\n");
      if (catatan.length > WALAS_MAX_CATATAN) {
        gagal.push(nama + ": lebih dari " + WALAS_MAX_CATATAN + " karakter");
        return;
      }

      // Cegah input yang diawali karakter formula diproses sebagai rumus.
      if (/^[=+\-@]/.test(catatan)) {
        catatan = "'" + catatan;
      }

      // Periksa rowIndex dari browser. Jika tidak cocok, cari berdasarkan Nama + Kelas.
      let row = Number(item.rowIndex) || 0;
      const idx = row - WALAS_BARIS_AWAL;
      const cocok = idx >= 0 && idx < n &&
        identitasSiswaKey_(
          String(idVals[idx][4] || "").trim(),
          String(idVals[idx][6] || "").trim()
        ) === key;

      if (!cocok) {
        const w = peta.get(key);
        if (!w) {
          gagal.push(nama + ": tidak ditemukan di sheet walas");
          return;
        }
        row = w.rowIndex;
      }

      const i = row - WALAS_BARIS_AWAL;
      colJ[i][0] = catatan;
      colB[i][0] = namaWaliTersimpan; // Kolom B otomatis; kolom C (NIP) tidak disentuh.
      disimpan++;
    });

    if (disimpan) {
      sh.getRange(WALAS_BARIS_AWAL, 2, n, 1).setValues(colB);
      sh.getRange(WALAS_BARIS_AWAL, 10, n, 1).setValues(colJ);
      SpreadsheetApp.flush();
    }

    return {
      success: gagal.length === 0,
      disimpan: disimpan,
      gagal: gagal,
      message: disimpan + " catatan tersimpan" +
        (gagal.length ? ", " + gagal.length + " gagal." : ".")
    };
  } catch (e) {
    return { success: false, message: e.message || String(e) };
  } finally {
    lock.releaseLock();
  }
}

/** Dipanggil saat API rapor mengambil detail siswa. */
function getCatatanWaliKelasForRapor(kunciIdentitasSiswa) {
  try {
    const w = petaWalas_().get(kunciIdentitasSiswa);
    return w ? w.catatan : "";
  } catch (e) {
    // Rapor tetap dapat ditampilkan jika sheet "walas" belum dibuat.
    return "";
  }
}