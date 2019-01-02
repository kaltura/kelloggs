import React from 'react';
import PropTypes from 'prop-types';
import { withStyles } from '@material-ui/core/styles';
import Typography from '@material-ui/core/Typography';
import Modal from '@material-ui/core/Modal';
import CircularProgress from '@material-ui/core/CircularProgress';
import MainView from './MainView';
import GlobalCommands from "./GlobalCommands";

const drawerHeight = 200;
const drawerPaddingTop = 24;

const styles = {
  root: {
    display: 'flex',
    flexDirection: 'column',
    height: '100%',
    boxSizing: 'border-box'
  },
  appBar: {
    background: 'rgb(60, 66, 82)'
  },
  grow: {
    flexGrow: 1
  },
  menuButton: {
    marginLeft: -12,
    marginRight: 20,
  },
  parameters: {
    height: drawerHeight,
    background: '#f5f5f5',
    boxShadow: '0px 2px 4px -1px rgba(0, 0, 0, 0.2), 0px 4px 5px 0px rgba(0, 0, 0, 0.14), 0px 1px 10px 0px rgba(0, 0, 0, 0.12)',
    color: 'rgba(0, 0, 0, 0.87)',
    padding: `12px ${drawerPaddingTop}px`,
    // transition: 'all 700ms'
  },
  parametersShift: {
    transform: `translateY(-${drawerHeight + drawerPaddingTop}px)`
  },
  content : {
    display: 'flex',
    flexDirection: 'column',
    flexGrow: 1,
    marginTop: -drawerHeight - drawerPaddingTop,
    transition: 'margin 700ms'
  },
  contentShift: {
    marginTop: 0
  },
  result: {
    display: 'flex',
    flexGrow: 1
  },
  loadingModal: {
    display: 'flex',
    flexDirection: 'column',
    justifyContent: 'center',
    alignItems: 'center',
    position: 'absolute',
    width: '100px',
    outline: 'none',
    background: 'rgba(255, 255, 255, 0.9)',
    borderRadius: '16px',
    padding: '10px',
    boxShadow: '0px 2px 4px -1px rgba(0, 0, 0, 0.2), 0px 4px 5px 0px rgba(0, 0, 0, 0.14), 0px 1px 10px 0px rgba(0, 0, 0, 0.12)',
    top: '50%',
    left: '50%',
    transform: 'translate(-50%, -50%)',
    '& > .marginTop' : {
      marginTop: '10px'
    }
  },
  alignCenter: {
    textAlign: 'center'
  }
};


class App extends React.Component {

  state = {
    jwt: null,
    hostUrl: null,
    serviceUrl: null,
    isReady: false,
  }

  _handleSetup = ({ data: {type, config }}) => {

    if (type !== 'config') {
      return;
    }

    this.setState({
      ks: config.ks,
      serviceUrl: config.serviceUrl,
      isReady: true
    })
  }

  componentWillUnmount() {
    window.removeEventListener('message', this._handleSetup);
  }

  componentDidMount() {
    window.addEventListener('message', this._handleSetup);
    window.parent.postMessage({ type: 'request-config'}, "*");
  }

  render() {
    const { classes } = this.props;
    const { expanded, canCollapse, parameters, isReady, ks, serviceUrl } = this.state;

    return (
      <div className={classes.root}>
        <MainView/>d
        {!isReady &&
          <Modal open={!isReady}>
            <div className={classes.loadingModal}>
              <Typography variant={'caption'} classes={{root: classes.alignCenter}}>Preparing
                application...</Typography>
              <CircularProgress className={'marginTop'}/>
            </div>
          </Modal>
        }
      </div>
    )
  }
}


App.propTypes = {
  classes: PropTypes.object.isRequired,
};

const AppWithStyles = withStyles(styles)(App);

function AppWrapper(props) {
  return (
    <GlobalCommands>
      <AppWithStyles/>
    </GlobalCommands>
  )
}
export default withStyles(styles)(AppWrapper);
