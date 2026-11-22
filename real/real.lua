math.randomseed(os.time())
local h = {
  ["User-Agent"] = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
  ["Accept"] = "application/json, text/plain, */*",
  ["Accept-Language"] = "en-US,en;q=0.9,ar;q=0.8",
  ["Accept-Encoding"] = "gzip, deflate, br",
  ["Authorization"] = "Bearer benchmark-placeholder-not-a-token-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  ["Cookie"] = "sid=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa; theme=dark; locale=en; _ga=GA1.1.1234567890.1700000000",
  ["Referer"] = "https://app.example.com/items?page=2",
  ["Origin"] = "https://app.example.com",
  ["X-Request-Id"] = "5f0c1d2e-8a7b-4c3d-9e1f-0a1b2c3d4e5f",
  ["X-Forwarded-For"] = "203.0.113.7, 10.0.0.12",
  ["X-Forwarded-Proto"] = "https",
  ["Sec-Fetch-Mode"] = "cors",
  ["Sec-Fetch-Site"] = "same-site",
}
local body = '{"customer": 4211, "currency": "EGP", "note": "leave at the door leave at the door leave at the door leave at the door leave at the door leave at the door ", "items": [{"id": 100, "qty": 1, "price": "149.90", "sku": "SKU-00000"}, {"id": 137, "qty": 2, "price": "149.90", "sku": "SKU-00911"}, {"id": 174, "qty": 3, "price": "149.90", "sku": "SKU-01822"}, {"id": 211, "qty": 1, "price": "149.90", "sku": "SKU-02733"}, {"id": 248, "qty": 2, "price": "149.90", "sku": "SKU-03644"}, {"id": 285, "qty": 3, "price": "149.90", "sku": "SKU-04555"}, {"id": 322, "qty": 1, "price": "149.90", "sku": "SKU-05466"}, {"id": 359, "qty": 2, "price": "149.90", "sku": "SKU-06377"}, {"id": 396, "qty": 3, "price": "149.90", "sku": "SKU-07288"}, {"id": 433, "qty": 1, "price": "149.90", "sku": "SKU-08199"}, {"id": 470, "qty": 2, "price": "149.90", "sku": "SKU-09110"}, {"id": 507, "qty": 3, "price": "149.90", "sku": "SKU-10021"}], "address": {"city": "Cairo", "street": "26 July Street", "building": "14", "phone": "+20-000-000-0000"}}'
if os.getenv("REAL_NO_RID") then h["X-Request-Id"] = nil end
local public = os.getenv("REAL_PUBLIC")
if public then h["Authorization"] = nil; h["Cookie"] = nil end
local up = os.getenv("REAL_UPLOAD_KB")
local upbody = up and string.rep("x", tonumber(up) * 1000) or nil
local bigkb = os.getenv("REAL_BIG_KB")
request = function()
  if upbody then h["Content-Type"] = "application/octet-stream"; return wrk.format("POST", "/api/upload", h, upbody) end
  if bigkb then return wrk.format("GET", "/api/big/" .. bigkb, h) end
  if os.getenv("REAL_POST") then
    h["Content-Type"] = "application/json"
    return wrk.format("POST", "/api/orders", h, body)
  end
  if public then return wrk.format("GET", "/api/catalog/" .. math.random(1, 900) .. "?page=2&sort=name", h) end
  if os.getenv("REAL_STATIC") then return wrk.format("GET", "/api/static/" .. math.random(1, 900) .. "?page=2&sort=name", h) end
  if os.getenv("REAL_HELLO") then return wrk.format("GET", "/hello", h) end
  return wrk.format("GET", "/api/items/" .. math.random(1, 900) .. "?page=2&sort=name", h)
end
