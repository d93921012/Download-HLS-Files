# Download-HLS-Files
Download and merge HTTP Live Streaming .ts files

HTTP Live Streaming (HLS) 藉由送出一連串包含影音資訊的小檔案來達成影音串流的功能，這些小檔案稱為 media segment files，
影音長度通常在 10 秒鐘左右。

An index file, or playlist, provides an ordered list of the URLs of the media segment files. 
Index files for HTTP Live Streaming are saved as .m3u8 playlists, an extension of the .m3u format used for MP3 playlists. 
The URL of the index file is accessed by clients, which then request the indexed files in sequence.

格式說明可參考 [Example Playlist Files for use with HTTP Live Streaming
](https://developer.apple.com/library/content/technotes/tn2288/_index.html)

此程式碼所參考的原始碼為 [Brainiarc7/Download and merge HTTP Live Streaming .ts files](https://gist.github.com/Brainiarc7/e7b6cd96e7bbee0a3cc8167fb2003373)
