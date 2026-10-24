#define _POSIX_C_SOURCE 200809L
#define _DARWIN_C_SOURCE 1
#include <sys/resource.h>
#include <sys/types.h>
#include <sys/wait.h>
#include <errno.h>
#include <fcntl.h>
#include <poll.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <time.h>
#include <unistd.h>

static double seconds(void)
{
    struct timespec t;
    if (clock_gettime(CLOCK_MONOTONIC, &t) != 0) { exit(126); }
    return (double)t.tv_sec + (double)t.tv_nsec / 1000000000.0;
}
static int send_all(int fd, const char *buffer, size_t count)
{
    while (count != 0) {
        ssize_t n = write(fd, buffer, count);
        if (n < 0 && errno == EINTR) { continue; }
        if (n <= 0) { return -1; }
        buffer += n;
        count -= (size_t)n;
    }
    return 0;
}
int main(int argc, char **argv)
{
    if (argc < 4) { return 126; }
    char *end;
    double budget = strtod(argv[1], &end);
    if (*end != '\0' || !(budget > 0.0 && budget <= 3600.0)) { return 126; }
    unsigned long cap = strtoul(argv[2], &end, 10);
    if (*end != '\0' || cap < 128 || cap > 33554432) { return 126; }
    int out[2], err[2];
    if (pipe(out) != 0 || pipe(err) != 0) { return 126; }
    double start = seconds();
    pid_t child = fork();
    if (child < 0) { return 126; }
    if (child == 0) {
        if (setpgid(0, 0) != 0) { perror("guard supervisor setpgid"); _exit(126); }
        if (dup2(out[1], STDOUT_FILENO) < 0 || dup2(err[1], STDERR_FILENO) < 0) { perror("guard supervisor dup2"); _exit(126); }
        close(out[0]); close(out[1]); close(err[0]); close(err[1]);
        struct rlimit cpu = {(rlim_t)budget + 1, (rlim_t)budget + 2};
        struct rlimit core = {0, 0};
        if (setrlimit(RLIMIT_CPU, &cpu) != 0) { perror("guard supervisor CPU limit"); _exit(126); }
        if (setrlimit(RLIMIT_CORE, &core) != 0) { perror("guard supervisor core limit"); _exit(126); }
        execvp(argv[3], &argv[3]);
        perror("guard supervisor exec");
        _exit(127);
    }
    (void)setpgid(child, child);
    close(out[1]); close(err[1]);
    if (fcntl(out[0], F_SETFL, O_NONBLOCK) < 0 || fcntl(err[0], F_SETFL, O_NONBLOCK) < 0) {
        kill(-child, SIGKILL); (void)waitpid(child, NULL, 0); return 126;
    }
    struct pollfd streams[2] = {{out[0], POLLIN, 0}, {err[0], POLLIN, 0}};
    unsigned long emitted = 0;
    int status = 0, done = 0, open_streams = 2;
    struct rusage usage = {0};
    const char *termination = "completed";
    while (!done || open_streams != 0) {
        if (seconds() - start >= budget && termination[0] == 'c') {
            termination = "timeout";
            kill(-child, SIGKILL);
        }
        (void)poll(streams, 2, 10);
        for (int i = 0; i < 2; ++i) {
            if (streams[i].fd < 0) { continue; }
            char buffer[4096];
            ssize_t n = read(streams[i].fd, buffer, sizeof(buffer));
            if (n > 0) {
                size_t available = cap - emitted;
                size_t take = (size_t)n < available ? (size_t)n : available;
                if (take > 0 && send_all(i == 0 ? STDOUT_FILENO : STDERR_FILENO, buffer, take) != 0) {
                    kill(-child, SIGKILL); (void)waitpid(child, NULL, 0); return 126;
                }
                emitted += (unsigned long)take;
                if ((size_t)n > take && termination[0] == 'c') {
                    termination = "output-limit";
                    kill(-child, SIGKILL);
                }
            } else if (n == 0 || (n < 0 && errno != EINTR && errno != EAGAIN)) {
                close(streams[i].fd); streams[i].fd = -1; --open_streams;
            }
        }
        if (!done) {
            pid_t waited = wait4(child, &status, WNOHANG, &usage);
            if (waited == child) { done = 1; }
            else if (waited < 0 && errno != EINTR) { kill(-child, SIGKILL); return 126; }
        }
    }
    /* Reap/kill remaining members of the command's process group, not unrelated jobs. */
    kill(-child, SIGKILL);
    int signal_number = WIFSIGNALED(status) ? WTERMSIG(status) : 0;
    int code = WIFEXITED(status) ? WEXITSTATUS(status) : 128 + signal_number;
    long long rss = (long long)usage.ru_maxrss;
#ifndef __APPLE__
    rss *= 1024;
#endif
    fprintf(stderr, "\nGUARD_METRICS {\"wall_ms\":%.3f,\"user_ms\":%.3f,\"system_ms\":%.3f,"
        "\"peak_rss_bytes\":%lld,\"exit\":%d,\"signal\":%d,\"termination\":\"%s\"}\n",
        (seconds() - start) * 1000.0,
        usage.ru_utime.tv_sec * 1000.0 + usage.ru_utime.tv_usec / 1000.0,
        usage.ru_stime.tv_sec * 1000.0 + usage.ru_stime.tv_usec / 1000.0,
        rss, code, signal_number, termination);
    if (termination[0] == 't') { return 124; }
    if (termination[0] == 'o') { return 125; }
    return code;
}
