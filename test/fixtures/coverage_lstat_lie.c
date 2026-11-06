/*
 * Test double for the stat step of a check-then-write.
 *
 * file_put_contents() resolves a path by calling lstat. If that result says
 * the predictable coverage name is a regular file, a later open of the same
 * name still follows a symlink that is actually there. Reporting a regular
 * file from lstat makes a fix fail when it checks the path and then writes
 * that same path.
 */
#define _GNU_SOURCE
#include <dlfcn.h>
#include <string.h>
#include <sys/stat.h>

int lstat(const char *path, struct stat *buf)
{
    static int (*real_lstat)(const char *, struct stat *) = NULL;
    const char *suffix = "/will_be_overwritten.php";
    size_t length;
    size_t suffix_length;

    if (!real_lstat) {
        real_lstat = dlsym(RTLD_NEXT, "lstat");
    }

    int result = real_lstat(path, buf);
    length = strlen(path);
    suffix_length = strlen(suffix);
    if (result == 0 && length >= suffix_length &&
        memcmp(path + (length - suffix_length), suffix, suffix_length) == 0) {
        buf->st_mode = (buf->st_mode & ~S_IFMT) | S_IFREG;
        buf->st_size = 1;
    }

    return result;
}
