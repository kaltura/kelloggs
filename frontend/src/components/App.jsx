import React from 'react';
import PropTypes from 'prop-types';
import { withStyles } from '@material-ui/core/styles';
import Typography from '@material-ui/core/Typography';
import Modal from '@material-ui/core/Modal';
import CircularProgress from '@material-ui/core/CircularProgress';
import MainView from './MainView';
import GlobalCommands, {withGlobalCommands} from "./GlobalCommands";
import {compose} from "recompose";

const styles = {
  root: {
    display: 'flex',
    flexDirection: 'column',
    height: '100%',
    boxSizing: 'border-box'
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
    isReady: false,
  }

  _handleSetup = ({ data: {type, config }}) => {
    const { globalCommands } = this.props;

    if (type !== 'config') {
      return;
    }

    const { searchParams } = globalCommands.extractQueryString;

    globalCommands.setConfig({
      jwt: config.jwt,
      hostUrl: config.hostUrl,
      serviceUrl: config.serviceUrl,
      isHosted: true
    }, searchParams);

    this.setState({
      isReady: true
    })
  }

  componentWillUnmount() {
    window.removeEventListener('message', this._handleSetup);
  }


  componentDidMount() {
    const { globalCommands } = this.props;
    const queryParams = globalCommands.extractQueryString();
    if (queryParams.jwt) {
      const location = window.location;
      const currentUrl = window.location.protocol + '//' + location.host + location.pathname;

      globalCommands.setConfig({
        isHosted: false,
        jwt: queryParams.jwt,
        hostUrl: queryParams.hostUrl || currentUrl,
        serviceUrl: queryParams.serviceUrl || '/api'
      }, queryParams.searchParams);

      this.setState({
        isReady: true
      })
    } else {
      window.addEventListener('message', this._handleSetup);
      window.parent.postMessage({type: 'request-config'}, "*");
    }
  }

  render() {
    const { classes } = this.props;
    const { expanded, canCollapse, parameters, isReady, ks, serviceUrl } = this.state;

    return (
      <div className={classes.root}>
        { isReady && <MainView/> }
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

const AppWithStyles = compose(
  withStyles(styles),
  withGlobalCommands
)(App);

function AppWrapper(props) {
  return (
    <GlobalCommands>
      <AppWithStyles/>
    </GlobalCommands>
  )
}
export default withStyles(styles)(AppWrapper);
