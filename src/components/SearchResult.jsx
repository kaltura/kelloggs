import React from 'react';
import PropTypes from 'prop-types';
import { withStyles } from '@material-ui/core/styles';
import Typography from '@material-ui/core/Typography';
import Button from '@material-ui/core/Button';
import CircularProgress from '@material-ui/core/CircularProgress';

const styles = {
  root: {
    position: 'relative',
    width: '100%',
    display: 'flex',
    flexDirection: 'column',
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
  backdrop: {
    backgroundColor: 'rgba(0, 0, 0, 0.5)',
    position: 'absolute',
    width: '100%',
    height: '100%'
  }
};


class SearchResult extends React.Component {

  state = {
    isProcessing: false,
  }

  componentDidMount() {

  }


  render() {
    const { classes, onClose} = this.props;
    const { isProcessing } = this.state;

    return (
      <div className={classes.root}>
        {isProcessing &&
        <React.Fragment>
          <div className={classes.backdrop}></div>
          <div className={classes.loadingModal}>
            <Typography variant={'caption'}>Processing...</Typography>
            <CircularProgress className={'marginTop'}/>
            <Button onClick={onClose} variant={'text'} className={'marginTop'}>Abort</Button>
          </div>
        </React.Fragment>
        }
      </div>

    )
  }
}


SearchResult.propTypes = {
  classes: PropTypes.object.isRequired,
};


export default withStyles(styles)(SearchResult);
