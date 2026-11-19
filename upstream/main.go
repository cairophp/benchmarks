// Command upstream is the slow service the HTTP client benchmarks call:
// /delay?ms=N answers after N milliseconds. /hello answers at once and is the
// plain Go baseline for the hello benchmark. /count is how many /delay calls
// it has answered.
package main

import (
	"flag"
	"log"
	"net/http"
	"strconv"
	"sync/atomic"
	"time"
)

func main() {
	addr := flag.String("addr", ":9100", "listen address")
	flag.Parse()

	var delayed atomic.Int64
	mux := http.NewServeMux()
	mux.HandleFunc("/delay", func(w http.ResponseWriter, r *http.Request) {
		delayed.Add(1)
		ms, _ := strconv.Atoi(r.URL.Query().Get("ms"))
		time.Sleep(time.Duration(ms) * time.Millisecond)
		w.Header().Set("Content-Type", "text/plain")
		w.Write([]byte("upstream"))
	})
	mux.HandleFunc("/count", func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(strconv.FormatInt(delayed.Load(), 10)))
	})
	mux.HandleFunc("/hello", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain")
		w.Write([]byte("Hello, World!"))
	})
	log.Fatal(http.ListenAndServe(*addr, mux))
}
