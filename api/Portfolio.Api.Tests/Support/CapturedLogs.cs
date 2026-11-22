using System.Collections.Concurrent;
using Microsoft.Extensions.Logging;

namespace Portfolio.Api.Tests.Support;

/// <summary>
/// A logger provider written by hand (ADR-026, point 10): it keeps every entry
/// the API writes, so that a test can read the log an operator would read.
/// </summary>
public sealed class CapturedLogs : ILoggerProvider
{
    private readonly ConcurrentQueue<LogEntry> _entries = new();

    /// <summary>Every entry so far, in the order they were written.</summary>
    public IReadOnlyList<LogEntry> Entries => [.. _entries];

    /// <summary>What the logger of one class wrote at one level, in order.</summary>
    public string[] Messages<TCategory>(LogLevel level) => [.. Of<TCategory>(level).Select(entry => entry.Message)];

    /// <summary>The entries the logger of one class wrote at one level, in order.</summary>
    public LogEntry[] Of<TCategory>(LogLevel level) =>
        [.. _entries.Where(entry => entry.Category == typeof(TCategory).FullName && entry.Level == level)];

    public ILogger CreateLogger(string categoryName) => new Logger(this, categoryName);

    public void Dispose()
    {
    }

    private sealed class Logger(CapturedLogs logs, string category) : ILogger
    {
        public IDisposable? BeginScope<TState>(TState state)
            where TState : notnull => null;

        public bool IsEnabled(LogLevel logLevel) => true;

        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter) =>
            logs._entries.Enqueue(new LogEntry(category, logLevel, formatter(state, exception), exception));
    }
}

/// <summary>One entry of the log: who wrote it, how serious, what it says, and the exception it carries.</summary>
public sealed record LogEntry(string Category, LogLevel Level, string Message, Exception? Exception);
