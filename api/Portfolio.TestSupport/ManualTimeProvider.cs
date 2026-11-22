using System.Threading.Channels;

namespace Portfolio.TestSupport;

/// <summary>
/// A clock that moves only when the test moves it, written by hand (ADR-026,
/// point 10). The code under test waits with
/// <see cref="Task.Delay(TimeSpan, TimeProvider, CancellationToken)"/>, which
/// asks this clock for a timer: the test learns the delay with
/// <see cref="NextTimerAsync"/>, then lets it elapse with <see cref="Advance"/>,
/// without sleeping.
/// </summary>
/// <remarks>
/// Only one-shot timers, the only kind <c>Task.Delay</c> asks for. Learning
/// that the code under test asked for a timer also tells the test that the
/// code reached its wait: whatever it did before is done.
/// </remarks>
public sealed class ManualTimeProvider(DateTimeOffset start) : TimeProvider
{
    private readonly Lock _lock = new();
    private readonly List<ManualTimer> _pending = [];
    private readonly Channel<TimeSpan> _asked = Channel.CreateUnbounded<TimeSpan>();
    private DateTimeOffset _now = start;

    public override DateTimeOffset GetUtcNow()
    {
        lock (_lock)
        {
            return _now;
        }
    }

    public override ITimer CreateTimer(TimerCallback callback, object? state, TimeSpan dueTime, TimeSpan period)
    {
        var timer = new ManualTimer(this, callback, state);
        timer.Change(dueTime, period);
        _asked.Writer.TryWrite(dueTime);
        return timer;
    }

    /// <summary>
    /// Waits until the code under test asks for its next timer, and gives its
    /// delay. Ten seconds at most, of real time: code that never waits again,
    /// because it crashed or ended, fails the test instead of hanging it.
    /// </summary>
    public async ValueTask<TimeSpan> NextTimerAsync(CancellationToken cancellationToken)
    {
        using var patience = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        patience.CancelAfter(TimeSpan.FromSeconds(10));
        try
        {
            return await _asked.Reader.ReadAsync(patience.Token);
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            throw new TimeoutException("The code under test asked for no timer within ten seconds.");
        }
    }

    /// <summary>Moves the clock forward, and fires the timers that fall due, in order.</summary>
    public void Advance(TimeSpan delta)
    {
        ManualTimer[] due;
        lock (_lock)
        {
            _now += delta;
            due = [.. _pending.Where(timer => timer.DueAt <= _now).OrderBy(timer => timer.DueAt)];
            _pending.RemoveAll(timer => timer.DueAt <= _now);
        }

        foreach (var timer in due)
        {
            timer.Fire();
        }
    }

    private void Schedule(ManualTimer timer, TimeSpan dueTime, TimeSpan period)
    {
        if (period != Timeout.InfiniteTimeSpan)
        {
            throw new NotSupportedException("The manual clock has only one-shot timers, the kind Task.Delay asks for.");
        }

        lock (_lock)
        {
            _pending.Remove(timer);
            if (dueTime != Timeout.InfiniteTimeSpan)
            {
                timer.DueAt = _now + dueTime;
                _pending.Add(timer);
            }
        }
    }

    private void Cancel(ManualTimer timer)
    {
        lock (_lock)
        {
            _pending.Remove(timer);
        }
    }

    private sealed class ManualTimer(ManualTimeProvider clock, TimerCallback callback, object? state) : ITimer
    {
        public DateTimeOffset DueAt { get; set; }

        public void Fire() => callback(state);

        public bool Change(TimeSpan dueTime, TimeSpan period)
        {
            clock.Schedule(this, dueTime, period);
            return true;
        }

        public void Dispose() => clock.Cancel(this);

        public ValueTask DisposeAsync()
        {
            Dispose();
            return ValueTask.CompletedTask;
        }
    }
}
