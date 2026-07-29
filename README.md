# PDO Class Database

Modern PHP 8.2+ projeleri için geliştirilmiş; güvenli, hızlı ve nesne yönelimli (Active Record & Query Builder) hafif bir PDO veritabanı kütüphanesidir[cite: 1, 2].

## Özellikler

* **PHP 8.2+ Uyumlu:** Constructor Property Promotion, `readonly` sınıflar ve modern özellikler[cite: 1, 2].
* **Active Record Desteği:** `dbObject` tabanlı model yönetimi.
* **Gelişmiş Query Builder:** Zincirlenebilir (chainable) metod yapısı ile kolay sorgulama.
* **İlişkiler (Relationships):** `belongsTo`, `hasMany`, `hasOne` desteği.
* **Soft Deletes:** Silinen kayıtları veritabanından tamamen silmeden saklayabilme[cite: 1].
* **Collection Sınıfı:** Veri dizilerini dizi veya nesne olarak esnek yönetebilme[cite: 1].
* **Statement Caching:** Yüksek performans için hazırlanan sorguların önbelleklenmesi.

---

## Kurulum

Projeyi Composer ile dahil edebilir veya `src/` klasörünü doğrudan projenize ekleyebilirsiniz:

```bash
composer require kullaniadi/pdo-class-database
