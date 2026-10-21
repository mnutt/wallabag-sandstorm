// A background event cannot use an expired browser session. Connect directly to
// SandstormApi through the bridge, using the proxy's pinned Go dependencies.
package main

import (
	"context"
	"fmt"
	"io"
	"net"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"capnproto.org/go/capnp/v3/rpc"
	"sandstorm.org/go/tempest/capnp/activity"
	"sandstorm.org/go/tempest/capnp/grain"
	bridge "sandstorm.org/go/tempest/capnp/sandstorm-http-bridge"
)

type ongoing struct {
	done chan struct{}
	once sync.Once
}

func (n *ongoing) Cancel(ctx context.Context, call activity.OngoingNotification_cancel) error {
	n.once.Do(func() { close(n.done) })
	return nil
}

func run() error {
	if len(os.Args) != 2 || (os.Args[1] != "complete" && os.Args[1] != "attention" && os.Args[1] != "awake") {
		return fmt.Errorf("expected complete, attention, or awake")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	conn, err := (&net.Dialer{}).DialContext(ctx, "unix", "/tmp/sandstorm-api")
	if err != nil {
		return err
	}
	client := rpc.NewConn(rpc.NewStreamTransport(conn), nil)
	defer client.Close()
	api, releaseAPI := bridge.SandstormHttpBridge(client.Bootstrap(ctx)).GetSandstormApi(ctx, nil)
	defer releaseAPI()
	if os.Args[1] == "awake" {
		// The generic stay-awake utility v0.1.5 does not exit on Cancel().
		// This adapter makes cancellation observable to our worker supervisor.
		server := &ongoing{done: make(chan struct{})}
		notification := activity.OngoingNotification_ServerToClient(server)
		defer notification.Release()
		future, release := api.Api().StayAwake(ctx, func(p grain.SandstormApi_stayAwake_Params) error {
			display, err := p.NewDisplayInfo()
			if err != nil {
				return err
			}
			caption, err := display.NewCaption()
			if err != nil {
				return err
			}
			if err = caption.SetDefaultText("wallabag is importing articles in the background."); err != nil {
				return err
			}
			return p.SetNotification(notification)
		})
		defer release()
		result, err := future.Struct()
		if err != nil {
			return err
		}
		if !result.Handle().IsValid() {
			return fmt.Errorf("Sandstorm returned an empty wake lock")
		}
		handle := result.Handle().AddRef()
		defer handle.Release()
		// Tell the supervisor acquisition succeeded before it consumes a job.
		fmt.Println("ready")
		inputClosed := make(chan struct{})
		go func() { io.Copy(io.Discard, os.Stdin); close(inputClosed) }()
		stopped, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM, syscall.SIGHUP)
		defer stop()
		select {
		case <-server.done:
		case <-inputClosed:
		case <-client.Done():
		case <-stopped.Done():
		}
		return nil
	}
	result, release := api.Api().BackgroundActivity(ctx, func(p grain.SandstormApi_backgroundActivity_Params) error {
		event, err := p.NewEvent()
		if err != nil {
			return err
		}
		event.SetType(1)
		caption := "Import complete"
		if os.Args[1] == "attention" {
			event.SetType(2)
			caption = "Import finished with entries needing attention"
		}
		if err = event.SetPath("sandstorm/imports"); err != nil {
			return err
		}
		notification, err := event.NewNotification()
		if err != nil {
			return err
		}
		text, err := notification.NewCaption()
		if err != nil {
			return err
		}
		return text.SetDefaultText(caption)
	})
	defer release()
	_, err = result.Struct()
	return err
}

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, "Sandstorm import API failed:", err)
		os.Exit(1)
	}
}
